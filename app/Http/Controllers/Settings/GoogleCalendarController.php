<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Exceptions\GoogleCalendar\GoogleCalendarStateMismatchException;
use App\Http\Controllers\Controller;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\UseCases\GoogleCalendar\AuthorizationUrlAction;
use App\UseCases\GoogleCalendar\ConnectAction;
use App\UseCases\GoogleCalendar\DisconnectAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * コーチの Google カレンダー連携(S-A-01)の HTTP エントリポイント。
 *
 * `role:coach` middleware で他ロールは 403(routes/web.php)。連携はコーチ本人の Google アカウントに
 * 対する 1:1 の付随情報であり、URL に対象コーチの ID を含まない(常に `auth()->user()` を操作する)ため、
 * `CoachAvailabilityPolicy` のような所有者確認 Policy は不要(本人以外を操作しうる余地がない)。
 *
 * 画面(面談設定タブ)は支給済みで変更しない。連携ボタン / 解除ボタンの遷移先ルート名
 * (`settings.google-calendar.redirect` / `.destroy`)は Blade 側の前提に合わせている。
 */
class GoogleCalendarController extends Controller
{
    /**
     * Google の認可画面へ遷移する。`redirect_path` はコールバック成功後に戻る LMS 内 URL
     * (面談設定タブが `?redirect_path=/settings/availability` を付与する)。
     * オープンリダイレクト対策として、内部の絶対パス(`/` 始まり、`//` ではない)のみ許可する。
     */
    public function redirect(Request $request, AuthorizationUrlAction $action): RedirectResponse
    {
        $redirectPath = $this->sanitizeRedirectPath($request->query('redirect_path'));

        $url = $action(
            $request->user(),
            route('settings.google-calendar.callback'),
            $redirectPath,
        );

        return redirect()->away($url);
    }

    /**
     * Google からの OAuth コールバック。`state` 不一致(なりすまし)は例外(403)として弾く。
     * ユーザーが Google 側で同意を拒否した場合(`error` クエリ)は 403 にはせず、通常のエラー表示に留める。
     */
    public function callback(Request $request, ConnectAction $action, GoogleCalendarService $service): RedirectResponse
    {
        $code = $request->query('code');
        $error = $request->query('error');

        if ($error !== null || ! is_string($code) || $code === '') {
            // ユーザーが Google 側で同意を拒否しただけの正規フロー(なりすまし検証の対象外: 認可コードが
            // 無く連携が成立し得ないため、他フローのセッションを壊すおそれもない)。
            $redirectPath = $service->consumeRedirectPath();
            $fallback = $redirectPath !== null ? redirect($redirectPath) : redirect()->route('settings.availability.index');

            return $fallback->with('error', 'Googleカレンダー連携がキャンセルされました。');
        }

        $state = $request->query('state');

        // なりすまし検証をセッションの変更(遷移先の取り出し含む)より先に行う。
        // 先にセッションを変更すると、偽のコールバックが state 不一致で拒否されるだけであっても、
        // 進行中の正規の連携フローの遷移先だけをセッションから消し去れてしまう。
        if (! is_string($state) || $state === '' || ! $service->stateMatchesSession($request->user(), $state)) {
            throw new GoogleCalendarStateMismatchException;
        }

        $redirectPath = $service->consumeRedirectPath();
        $fallback = $redirectPath !== null ? redirect($redirectPath) : redirect()->route('settings.availability.index');

        $action(
            $request->user(),
            $code,
            $state,
            route('settings.google-calendar.callback'),
        );

        return $fallback->with('success', 'Googleカレンダーと連携しました。');
    }

    public function destroy(Request $request, DisconnectAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('settings.availability.index')
            ->with('success', 'Googleカレンダー連携を解除しました。');
    }

    private function sanitizeRedirectPath(?string $redirectPath): string
    {
        $default = route('settings.availability.index', absolute: false);

        if ($redirectPath === null || $redirectPath === '') {
            return $default;
        }

        // '/' 始まりかつ '//' ではない(スキーム相対 URL によるオープンリダイレクトを拒否)内部パスのみ許可
        if (! str_starts_with($redirectPath, '/') || str_starts_with($redirectPath, '//')) {
            return $default;
        }

        return $redirectPath;
    }
}
