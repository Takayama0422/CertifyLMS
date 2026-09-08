<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPreference\StoreAvatarRequest;
use App\UseCases\UserPreference\DestroyAvatarAction;
use App\UseCases\UserPreference\StoreAvatarAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 本人アバター画像アップロード / 削除 Controller(`POST|DELETE /settings/avatar`)。全ロール共通・本人のみ。
 */
class AvatarController extends Controller
{
    public function store(StoreAvatarRequest $request, StoreAvatarAction $action): RedirectResponse
    {
        $action($request->user(), $request->file('avatar'));

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アイコン画像を更新しました。');
    }

    public function destroy(Request $request, DestroyAvatarAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アイコン画像を削除しました。');
    }
}
