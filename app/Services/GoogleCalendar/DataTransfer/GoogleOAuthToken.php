<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar\DataTransfer;

use Carbon\CarbonInterface;

/**
 * Google OAuth のトークン交換 / リフレッシュ結果を表す値オブジェクト。
 *
 * `refreshToken` は Google がレスポンスに含めないことがある(2 回目以降の認可等)ため null を許容する。
 * その場合は呼び出し元(GoogleCalendarService)が既存の refresh_token を保持し続ける。
 */
final readonly class GoogleOAuthToken
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonInterface $expiresAt,
    ) {}
}
