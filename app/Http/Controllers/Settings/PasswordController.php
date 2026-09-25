<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * 本人パスワード変更 Controller(`PUT /settings/password`)。
 *
 * バリデーション + 更新は Fortify 標準の `UpdatesUserPasswords` 実装(`App\Actions\Fortify\UpdateUserPassword`、
 * `PasswordValidationRules` 経由で共通パスワード規則を使用)に委譲する。config/fortify.php の features コメントで
 * 「Fortify 既定の PUT /user/password ルートは登録せず、本 Controller 経由で扱う」と明記されている設計に対応する。
 *
 * バリデーション失敗時は Fortify Action 側で `updatePassword` 名前付きエラーバッグに投げるため、
 * resources/views/settings/_partials/tab-password.blade.php の `$errors->updatePassword` 参照と対になる。
 */
class PasswordController extends Controller
{
    public function update(Request $request, UpdatesUserPasswords $updater): RedirectResponse
    {
        $updater->update($request->user(), $request->all());

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}
