<?php

declare(strict_types=1);

namespace App\Providers;

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
