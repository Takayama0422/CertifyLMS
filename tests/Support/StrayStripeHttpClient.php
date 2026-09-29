<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe SDK の HTTP クライアントを差し替える「呼ばれたら違反」の実装(T-A-04)。
 * `PaymentGatewayContract` を経由せず SDK を直接使う経路(`StripePaymentGatewayService` 等)からの
 * 実通信を、SDK の通信層で止めて違反として記録する。
 * SDK のリクエスト内容まで検証したいテストは、代わりに `FakeStripeHttpClient` を設定する。
 */
final class StrayStripeHttpClient implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        ExternalRequestGuard::record('stripe-sdk', strtoupper((string) $method).' '.$absUrl);

        throw new ApiConnectionException('未モックの Stripe SDK 通信をテストのガードが遮断した。');
    }
}
