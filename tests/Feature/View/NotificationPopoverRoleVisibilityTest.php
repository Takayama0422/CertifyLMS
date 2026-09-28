<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S-A-05 通知ポップオーバーのロール別表示可否の検証。
 *
 * 要件シート S4「ポップオーバー表示」は 受講生○ / コーチ○ / 管理者×(拒否時: —、つまり 403 等の
 * 拒否ステータスは持たない)。支給済みの `topbar.blade.php` / `notification-popover.blade.php` は
 * ロールで出し分けておらず(ベルは全ロールに描画)、支給 Blade へロール判定用の `data-*` や
 * `<meta>` を追加することも支給画面の変更にあたるため不可。
 *
 * したがって表示可否の判定材料は `GET /api/v1/notifications` の応答に載る `popover_visible`
 * フラグのみであり(`App\Http\Controllers\Api\NotificationController::index()`)、
 * `resources/js/notifications/popover.js` はこのフラグを見て、管理者のときはポップオーバーを開かない
 * (ベル自体は支給画面のまま残す)。PHP 側で検証できるのはこのフラグの値までであり、JS が実際に
 * 開かない挙動そのものは `npm run build` の成功確認とコードレビューに委ねる。
 *
 * (旧: `meta[name="auth-user-role"]` を Blade に追加してそれを JS が読む案は、支給画面の変更に
 * あたるとして差し戻された。本テストはその差し戻し後の実装を検証する。)
 */
class NotificationPopoverRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_response_reports_popover_visible_true(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonPath('popover_visible', true);
    }

    public function test_coach_response_reports_popover_visible_true(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonPath('popover_visible', true);
    }

    public function test_admin_response_reports_popover_visible_false(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/notifications');

        // 要件シート S4 の「拒否時: —」どおり、管理者でも 200 のままフラグだけが false になる
        // (ロールによる 403 拒否ではない。閲覧/既読化自体は S-B-04 により 3 ロール共通で許可)。
        $response->assertOk();
        $response->assertJsonPath('popover_visible', false);
    }
}
