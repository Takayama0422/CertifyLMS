<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stripe Webhook 署名検証テスト用ヘルパー。
 *
 * Stripe の署名アルゴリズム(`t=<timestamp>,v1=<hmac_sha256("{timestamp}.{payload}", secret)>`)を
 * 実装側(`\Stripe\WebhookSignature`)と同一のロジックで再現し、正規署名を生成する。
 * 署名検証はネットワーク通信を伴わないローカルな検証処理のため、モックではなく実アルゴリズムで検証する。
 */
trait StripeWebhookTestHelpers
{
    /**
     * 指定 payload に対する正規の `Stripe-Signature` ヘッダ値を生成する。
     */
    protected function validStripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Stripe Event 相当の JSON payload を組み立てる(必要最小限のフィールドのみ)。
     *
     * @param array<string, mixed> $sessionOverrides
     */
    protected function stripeCheckoutEventPayload(
        string $eventId,
        string $type,
        array $sessionOverrides = [],
    ): string {
        $session = array_merge([
            'id' => 'cs_test_'.$eventId,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_'.$eventId,
        ], $sessionOverrides);

        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => $session,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
