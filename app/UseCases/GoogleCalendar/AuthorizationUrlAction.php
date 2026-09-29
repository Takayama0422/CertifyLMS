<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;

/**
 * コーチ本人の Google 認可 URL を発行するユースケース(state をセッションへ保存する副作用を伴う)。
 */
final class AuthorizationUrlAction
{
    public function __construct(
        private readonly GoogleCalendarService $service,
    ) {}

    public function __invoke(User $coach, string $redirectUri, string $redirectPath): string
    {
        return $this->service->authorizationUrl($coach, $redirectUri, $redirectPath);
    }
}
