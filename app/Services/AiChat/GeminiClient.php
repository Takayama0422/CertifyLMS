<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini API(generateContent)への薄いラッパー。
 *
 * - `config('ai-chat.gemini.*')` から接続設定を読む。設計上、外部通信はこのクラス経由に一本化し、
 *   テストは `Http::fake()` でモックする(実通信は発生させない)。
 * - API キー未設定 / 通信失敗 / 空応答は例外を投げず `GeminiReplyResult::failure()` を返す。
 *   呼び出し側(`StoreMessageAction`)は「AI 応答に失敗しても受講生の質問は残す」フローを
 *   分岐なしで書けるようにするため。
 * - 一時的なエラー(429 / 5xx / 接続例外)は `retry_times` 回まで自動再試行する。ただし
 *   `timeout`(1 回あたり最大待ち時間)× 試行回数がそのまま合計待ち時間になり得るため、
 *   `max_total_wait_seconds` で合計待ち時間の上限を別途設ける(超えたら以後は再試行しない)。
 *   受講生のリクエストがこの時間より長くブロックされ続けることはない。
 */
class GeminiClient
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com',
        private readonly int $timeoutSeconds = 30,
        private readonly int $retryTimes = 2,
        private readonly int $retryDelayMs = 200,
        private readonly int $maxTotalWaitSeconds = 45,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('ai-chat.gemini.api_key'),
            model: (string) config('ai-chat.gemini.model', 'gemini-2.5-flash'),
            baseUrl: (string) config('ai-chat.gemini.base_url', 'https://generativelanguage.googleapis.com'),
            timeoutSeconds: (int) config('ai-chat.gemini.timeout', 30),
            retryTimes: (int) config('ai-chat.gemini.retry_times', 2),
            retryDelayMs: (int) config('ai-chat.gemini.retry_delay_ms', 200),
            maxTotalWaitSeconds: (int) config('ai-chat.gemini.max_total_wait_seconds', 45),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * @param array<int, array{role: 'user'|'assistant', content: string}> $history 直近の会話履歴
     *                                                                              (このメッセージより前のもの。role は内部表現のまま渡し、Gemini 用の user/model への変換は本メソッド内で行う)
     */
    public function generateReply(string $systemInstruction, array $history, string $userMessage): GeminiReplyResult
    {
        if (! $this->isConfigured()) {
            return GeminiReplyResult::failure(upstreamStatus: null, errorDetail: 'api_key_missing');
        }

        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        $payload = ['contents' => $contents];
        if ($systemInstruction !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        return $this->call($payload);
    }

    /**
     * 会話タイトルの自動生成用。短い日本語タイトルのみを返すよう指示する。
     */
    public function generateTitle(string $firstUserMessage, string $firstAssistantReply): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $prompt = <<<PROMPT
            以下は学習相談 AI チャットの最初のやり取りです。この会話に付ける短い日本語タイトルを
            1 行・20 文字以内・記号や引用符なしで出力してください。タイトル以外は一切出力しないでください。

            受講生: {$firstUserMessage}
            AI: {$firstAssistantReply}
            PROMPT;

        $result = $this->call([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
        ]);

        if (! $result->successful || $result->content === null) {
            return null;
        }

        $title = trim(str_replace(["\n", '"', '「', '」'], '', $result->content));

        return $title === '' ? null : mb_substr($title, 0, 100);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(array $payload): GeminiReplyResult
    {
        $started = microtime(true);

        try {
            $response = $this->pendingRequest($started)->post(
                "/v1beta/models/{$this->model}:generateContent",
                $payload
            );
        } catch (Throwable $e) {
            return GeminiReplyResult::failure(
                upstreamStatus: null,
                errorDetail: $e->getMessage(),
                responseTimeMs: $this->elapsedMs($started),
            );
        }

        $elapsedMs = $this->elapsedMs($started);

        if ($response->failed()) {
            return GeminiReplyResult::failure(
                upstreamStatus: $response->status(),
                errorDetail: $this->extractErrorMessage($response->json()) ?? $response->body(),
                responseTimeMs: $elapsedMs,
            );
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            return GeminiReplyResult::failure(
                upstreamStatus: $response->status(),
                errorDetail: 'empty_response',
                responseTimeMs: $elapsedMs,
            );
        }

        $inputTokens = data_get($response->json(), 'usageMetadata.promptTokenCount');
        $outputTokens = data_get($response->json(), 'usageMetadata.candidatesTokenCount');

        return GeminiReplyResult::success(
            content: $text,
            model: $this->model,
            responseTimeMs: $elapsedMs,
            inputTokens: is_int($inputTokens) ? $inputTokens : null,
            outputTokens: is_int($outputTokens) ? $outputTokens : null,
        );
    }

    private function pendingRequest(float $startedAt): PendingRequest
    {
        $maxTotalWaitMs = max(0, $this->maxTotalWaitSeconds) * 1000;

        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->retry(
                times: max(1, $this->retryTimes + 1),
                sleepMilliseconds: $this->retryDelayMs,
                when: function (Throwable $exception) use ($startedAt, $maxTotalWaitMs): bool {
                    // 合計待ち時間が上限を超えていれば、まだ再試行の余地(times)が残っていても打ち切る。
                    if ($this->elapsedMs($startedAt) >= $maxTotalWaitMs) {
                        return false;
                    }

                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        return in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
                    }

                    return false;
                },
                throw: false,
            );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param mixed $json
     */
    private function extractErrorMessage(mixed $json): ?string
    {
        $message = data_get($json, 'error.message');

        return is_string($message) ? $message : null;
    }
}
