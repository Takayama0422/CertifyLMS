<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * `GeminiClient` の呼び出し結果を表す不変な値オブジェクト。
 *
 * 例外を投げる代わりにこれを返すことで、呼び出し側(`StoreMessageAction`)は
 * 成功 / 失敗を分岐しつつ「失敗しても受講生のメッセージ自体は残す」フローを素直に書ける。
 */
final class GeminiReplyResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $content,
        public readonly ?string $model,
        public readonly ?int $responseTimeMs,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $upstreamStatus,
        public readonly ?string $errorDetail,
    ) {}

    public static function success(
        string $content,
        string $model,
        int $responseTimeMs,
        ?int $inputTokens,
        ?int $outputTokens,
    ): self {
        return new self(
            successful: true,
            content: $content,
            model: $model,
            responseTimeMs: $responseTimeMs,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            upstreamStatus: null,
            errorDetail: null,
        );
    }

    public static function failure(
        ?int $upstreamStatus,
        string $errorDetail,
        ?int $responseTimeMs = null,
    ): self {
        return new self(
            successful: false,
            content: null,
            model: null,
            responseTimeMs: $responseTimeMs,
            inputTokens: null,
            outputTokens: null,
            upstreamStatus: $upstreamStatus,
            errorDetail: $errorDetail,
        );
    }

    /**
     * message-bubble.blade.php / message-renderer.js のエラー文言分岐(`str_contains($errorDetail, '429')` 等)
     * が引っかかるよう、HTTP ステータスコードを文字列として含める形式に整形する。
     */
    public function describeError(): string
    {
        if ($this->upstreamStatus !== null) {
            return "Gemini API error (HTTP {$this->upstreamStatus}): {$this->errorDetail}";
        }

        return $this->errorDetail ?? 'unknown_error';
    }
}
