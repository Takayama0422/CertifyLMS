<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AiChat;

use App\Services\AiChat\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * T-A-04: `GeminiClient` の通信エラー / 一時的なエラーからの再試行の境界の検証(既存の `GeminiClientTest` を補完する)。
 *
 * モック手法: `Http::fake()` と `Http::sequence()`(応答の並び)を使う。通信エラーは `fake()` のコールバックから
 * `ConnectionException` を投げて再現する。いずれも実通信は発生しない。
 */
#[Group('external')]
#[Group('gemini')]
class GeminiClientResilienceTest extends TestCase
{
    private function ok(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function retryableStatuses(): array
    {
        return [
            '429(利用枠超過)' => [429],
            '500' => [500],
            '502' => [502],
            '504' => [504],
        ];
    }

    #[DataProvider('retryableStatuses')]
    public function test_retryable_status_is_retried_and_the_second_attempt_succeeds(int $status): void
    {
        Http::fake(['*' => Http::sequence()->push(['error' => ['message' => 'transient']], $status)->push($this->ok('再試行で成功'), 200)]);

        $result = (new GeminiClient(apiKey: 'k', retryTimes: 2, retryDelayMs: 0))->generateReply('', [], 'hello');

        $this->assertTrue($result->successful);
        $this->assertSame('再試行で成功', $result->content);
        Http::assertSentCount(2);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonRetryableStatuses(): array
    {
        return [
            '400(リクエスト不正)' => [400],
            '401(認証エラー)' => [401],
            '403(権限なし / キー無効)' => [403],
            '404(モデルなし)' => [404],
        ];
    }

    #[DataProvider('nonRetryableStatuses')]
    public function test_client_errors_other_than_429_are_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'client error']], $status)]);

        $result = (new GeminiClient(apiKey: 'k', retryTimes: 2, retryDelayMs: 0))->generateReply('', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame($status, $result->upstreamStatus);
        Http::assertSentCount(1);
    }

    public function test_a_connection_error_is_retried_and_the_second_attempt_succeeds(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($this->ok('接続エラーの後で成功'), 200);
        });

        $result = (new GeminiClient(apiKey: 'k', retryTimes: 2, retryDelayMs: 0))->generateReply('', [], 'hello');

        $this->assertTrue($result->successful);
        $this->assertSame('接続エラーの後で成功', $result->content);
        $this->assertSame(2, $attempts);
    }

    public function test_a_connection_error_on_every_attempt_fails_after_exhausting_the_retries_without_throwing(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 6: Could not resolve host');
        });

        $result = (new GeminiClient(apiKey: 'k', retryTimes: 2, retryDelayMs: 0))->generateReply('', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertNull($result->upstreamStatus);
        $this->assertSame(3, $attempts, '初回 + retryTimes(2)');
    }

    public function test_a_response_without_candidates_is_an_empty_response_failure(): void
    {
        Http::fake(['*' => Http::response(['usageMetadata' => ['promptTokenCount' => 3]], 200)]);

        $result = (new GeminiClient(apiKey: 'k', retryDelayMs: 0))->generateReply('', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame('empty_response', $result->errorDetail);
    }

    public function test_a_blank_system_instruction_is_omitted_from_the_request(): void
    {
        Http::fake(['*' => Http::response($this->ok('ok'), 200)]);

        (new GeminiClient(apiKey: 'k', retryDelayMs: 0))->generateReply('', [], 'hello');
        Http::assertSent(fn ($request) => ! array_key_exists('systemInstruction', $request->data()));
    }

    public function test_assistant_history_is_sent_with_the_model_role(): void
    {
        Http::fake(['*' => Http::response($this->ok('ok'), 200)]);

        (new GeminiClient(apiKey: 'k', retryDelayMs: 0))->generateReply('SYS', [
            ['role' => 'user', 'content' => 'こんにちは'],
            ['role' => 'assistant', 'content' => 'はい'],
        ], '続き');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['systemInstruction']['parts'][0]['text'] === 'SYS'
                && array_map(fn ($c) => $c['role'], $data['contents']) === ['user', 'model', 'user'];
        });
    }
}
