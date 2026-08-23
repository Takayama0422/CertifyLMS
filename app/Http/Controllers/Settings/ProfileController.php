<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPreference\UpdateProfileRequest;
use App\UseCases\UserPreference\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 本人プロフィール表示・編集 Controller(`GET|PATCH /settings/profile`)。
 *
 * 全ロール共通・修了済(graduated)受講生も利用可のため、ルートには `auth` middleware のみを付与し
 * `role:` / `active-learning` は付与しない(routes/web.php 参照)。対象は常に `$request->user()` 本人。
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.profile', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $action($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'プロフィールを更新しました。');
    }
}
