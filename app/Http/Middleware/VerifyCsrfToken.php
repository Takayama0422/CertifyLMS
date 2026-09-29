<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // 決済サービス(Stripe)からの通知は認証なし・CSRF トークンを持たないため除外する。
        // 正当性の担保は署名検証(StripeWebhookController)側で行う。
        'webhooks/stripe',
    ];
}
