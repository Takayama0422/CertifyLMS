<?php

declare(strict_types=1);

namespace App\Exceptions\GoogleCalendar;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * OAuth コールバックの `state` パラメータが、認可 URL 発行時にセッションへ保存した値と一致しない場合の例外。
 *
 * CSRF / なりすまし(第三者が偽の callback リクエストを送りつける攻撃)を拒否するための検証。
 * HTTP 403 として Laravel 標準の 403 エラーページに任せる(Handler.php の方針: 403 は個別 redirect
 * 対応せず、Policy 拒否と同様デフォルト挙動に委ねる)。
 */
final class GoogleCalendarStateMismatchException extends AccessDeniedHttpException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Google カレンダー連携の認可情報を検証できませんでした。最初からやり直してください。', $previous);
    }
}
