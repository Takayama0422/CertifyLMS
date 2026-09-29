<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AiChat\GeminiClient;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleApiCalendarClient;
use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config('ai-chat.gemini.*') から生成した GeminiClient をコンテナに登録する。
        // これにより Controller / Action は Constructor Injection するだけで設定済みインスタンスを受け取れる。
        // テストでは `$this->app->instance(GeminiClient::class, ...)` で差し替え可能(外部通信はしない設計)。
        $this->app->singleton(GeminiClient::class, fn () => GeminiClient::fromConfig());
        // Google カレンダー連携(S-A-01): 実通信の実装を差し替え可能にするためインターフェース越しに束縛する。
        // テストではこのインターフェースをフェイク実装に差し替え、実通信を一切発生させない。
        $this->app->bind(GoogleCalendarClient::class, GoogleApiCalendarClient::class);
    }

    public function boot(): void
    {
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);
    }
}
