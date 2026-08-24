<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ============================================================
// 通知 JSON API(S-A-05, TopBar 通知ポップオーバー用)
// Sanctum Cookie 認証(auth:sanctum)。認証ユーザー本人の通知のみ扱う(認可は NotificationPolicy、
// Web 版 `notifications.*` と共用)。Web 画面側の経路へは統合しない(API 系統のまま維持、要件シート S6)。
// ============================================================
Route::middleware('auth:sanctum')->prefix('v1/notifications')->name('api.v1.notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('read-all', [NotificationController::class, 'markAllAsRead'])->name('markAllAsRead');
    Route::post('{notification}/read', [NotificationController::class, 'markAsRead'])->name('markAsRead');
});
