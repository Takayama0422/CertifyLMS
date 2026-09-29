<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\UseCases\Notification\MarkAllAsReadAction;
use App\UseCases\Notification\MarkAsReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * TopBar 通知ポップオーバー(S-A-05)向けの通知 JSON API。
 * Sanctum Cookie 認証(`auth:sanctum`, `routes/api.php`)で保護し、認証ユーザー本人の通知のみを扱う。
 * 他人宛の通知 ID を指定した既読化は `NotificationPolicy`(Web 版一覧と共用)で拒否する。
 *
 * ポップオーバーは「最新分のみ表示、深掘りはフルページに委譲」の方針(要件シート S12-13)のため、
 * 一覧は直近 {@see self::DISPLAY_LIMIT} 件のみ返す。未読件数バッジはこの上限と無関係に実数を返す。
 *
 * ロールによる出し分け(要件シート S4: 「ポップオーバー表示」受講生○ / コーチ○ / 管理者×、拒否時は「—」)は、
 * 通知の閲覧・既読化そのものの認可(S-B-04: 「通知閲覧/既読化」は 3 ロール共通で本人のみ)とは別レイヤーの
 * UI 表示可否であり、拒否ステータスを持たない。したがって本エンドポイントはロールで 403 にせず、
 * 常に本人の通知を 200 で返した上で `popover_visible` フラグに管理者かどうかを載せる。
 * 支給済みの TopBar(`topbar.blade.php`)はロールで出し分けていないため、ポップオーバーを開くかどうかの
 * 判断は JS 側(`resources/js/notifications/popover.js`)がこのフラグを見て行う。
 */
final class NotificationController extends Controller
{
    private const DISPLAY_LIMIT = 10;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()->limit(self::DISPLAY_LIMIT)->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'unread_count' => $user->unreadNotifications()->count(),
            'popover_visible' => $user->role !== UserRole::Admin,
        ]);
    }

    public function markAsRead(DatabaseNotification $notification, MarkAsReadAction $action, Request $request): JsonResponse
    {
        $this->authorize('update', $notification);

        $action($notification);

        return response()->json([
            'data' => new NotificationResource($notification->fresh()),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request, MarkAllAsReadAction $action): JsonResponse
    {
        $updatedCount = $action($request->user());

        return response()->json([
            'updated_count' => $updatedCount,
            'unread_count' => 0,
        ]);
    }
}
