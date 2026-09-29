<?php

declare(strict_types=1);

namespace App\Exceptions\Payment;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * 決済サービス(Stripe)の鍵が未設定、または Checkout Session 作成時の通信でエラーが発生した際に throw する(HTTP 503)。
 *
 * 「鍵が未設定でもアプリが壊れず既存機能が動くこと」の要件に対応するためのガード。
 * 決済チェックアウト機能自体は鍵がなければ利用できないが、未設定時に致命的エラー(500)ではなく
 * 制御されたレスポンスへ倒すことで、アプリ全体の可用性には影響しない。
 */
final class PaymentGatewayUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            null,
            'ただいま決済機能をご利用いただけません。しばらくしてから再度お試しください。',
            $previous,
        );
    }
}
