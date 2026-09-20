<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * 認証ユーザー自身の通知一覧・既読化を扱う Controller(サイドバー「通知」の遷移先)。
 * 他人宛の通知の閲覧・既読化は Policy(`NotificationPolicy`)で拒否する。
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $request->string('tab', 'all')->toString();
        $tab = in_array($tab, ['all', 'unread'], true) ? $tab : 'all';

        $query = $tab === 'unread'
            ? $user->unreadNotifications()
            : $user->notifications();

        $notifications = $query->paginate(20)->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'tab' => $tab,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(DatabaseNotification $notification, MarkAsReadAction $action): RedirectResponse
    {
        $this->authorize('update', $notification);

        $action($notification);

        $url = is_array($notification->data) ? ($notification->data['url'] ?? null) : null;

        return redirect($url ?? route('notifications.index'));
    }

    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('notifications.index')
            ->with('success', 'すべての通知を既読にしました。');
    }
}
