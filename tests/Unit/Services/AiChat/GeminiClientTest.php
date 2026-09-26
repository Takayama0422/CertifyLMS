<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AiChat;

use App\Services\AiChat\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GeminiClient` の単体テスト。実通信は発生させず `Http::fake()` でモックする。
 */
class GeminiClientTest extends TestCase
{
    public function test_generate_reply_returns_failure_when_api_key_missing(): void
    {
        Http::fake();

        $client = new GeminiClient(apiKey: null);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame('api_key_missing', $result->errorDetail);
        Http::assertNothingSent();
    }

    public function test_generate_reply_returns_success_on_200(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'こんにちは']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ], 200),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertTrue($result->successful);
        $this->assertSame('こんにちは', $result->content);
        $this->assertSame(10, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
    }

    public function test_generate_reply_sends_api_key_as_header_not_body(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
            ], 200),
        ]);

        $client = new GeminiClient(apiKey: 'secret-key', retryDelayMs: 0);
        $client->generateReply('system', [], 'hello');

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'secret-key')
                && ! array_key_exists('key', $request->data());
        });
    }

    public function test_generate_reply_retries_on_503_then_succeeds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'retried']]]]]], 200),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 2, retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertTrue($result->successful);
        $this->assertSame('retried', $result->content);
        Http::assertSentCount(2);
    }

    public function test_generate_reply_gives_up_after_exhausting_retries(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'still overloaded']], 503),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 2, retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame(503, $result->upstreamStatus);
        Http::assertSentCount(3); // 初回 + retryTimes(2)
    }

    public function test_generate_reply_stops_retrying_once_max_total_wait_seconds_is_exceeded(): void
    {
        // timeout × 試行回数の合計待ち時間に上限を設ける(レビュー指摘 15)。上限を 0 秒にすることで、
        // retryTimes に余地があっても初回の 1 回で打ち切られることを確認する。
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'still overloaded']], 503),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 5, retryDelayMs: 0, maxTotalWaitSeconds: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        Http::assertSentCount(1);
    }

    public function test_generate_reply_does_not_retry_on_400(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'bad request']], 400),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 2, retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame(400, $result->upstreamStatus);
        Http::assertSentCount(1);
    }

    public function test_generate_reply_handles_empty_response(): void
    {
        Http::fake([
            '*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '']]]]]], 200),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertSame('empty_response', $result->errorDetail);
    }

    public function test_generate_reply_handles_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 1, retryDelayMs: 0);

        $result = $client->generateReply('system', [], 'hello');

        $this->assertFalse($result->successful);
        $this->assertNull($result->upstreamStatus);
    }

    public function test_generate_title_returns_trimmed_text(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => "  二分探索木の基礎\n"]]]]],
            ], 200),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryDelayMs: 0);

        $title = $client->generateTitle('質問', '回答');

        $this->assertSame('二分探索木の基礎', $title);
    }

    public function test_generate_title_returns_null_when_key_missing(): void
    {
        Http::fake();

        $client = new GeminiClient(apiKey: null);

        $this->assertNull($client->generateTitle('質問', '回答'));
        Http::assertNothingSent();
    }

    public function test_generate_title_returns_null_on_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', retryTimes: 0, retryDelayMs: 0);

        $this->assertNull($client->generateTitle('質問', '回答'));
    }
}
