<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe SDK の HTTP クライアントのテストダブル(T-A-04)。
 * `ApiRequestor::setHttpClient()` で差し込み、SDK が組み立てたリクエスト(URL / ヘッダ / パラメータ)を
 * 記録し、あらかじめ設定した応答(本文・ステータス)または通信例外を返す。
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly array $body = [],
        private readonly int $status = 200,
        private readonly ?ApiConnectionException $exception = null,
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => strtoupper((string) $method),
            'url' => (string) $absUrl,
            'headers' => (array) $headers,
            'params' => (array) $params,
        ];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return [json_encode($this->body, JSON_THROW_ON_ERROR), $this->status, []];
    }
}
