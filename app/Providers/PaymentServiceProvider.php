<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Contracts\PaymentGatewayContract;
use App\Services\StripePaymentGatewayService;
use Illuminate\Support\ServiceProvider;

/**
 * 追加面談購入(S-A-03)Feature の依存登録を担う ServiceProvider。
 *
 * PaymentGatewayContract に対する正規実装として StripePaymentGatewayService を bind する。
 * テストはこの Container バインディングを `$this->app->instance(PaymentGatewayContract::class, $fake)` 等で
 * 差し替え、実通信を発生させずに Checkout Session 作成ロジックを検証する。
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGatewayContract::class, StripePaymentGatewayService::class);
    }
}
