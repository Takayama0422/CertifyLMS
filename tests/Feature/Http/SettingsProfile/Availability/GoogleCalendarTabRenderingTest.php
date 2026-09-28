<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Availability;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S-A-01 コードレビュー指摘対応: 面談設定ページ(`settings.availability.index`)が描画する
 * Google カレンダー連携カード(連携中 / 未連携の出し分け、連携・解除ボタンの出現)を検証する。
 *
 * 過去に「画面が参照している関係名 / 項目名と実装の名前がずれてボタンが 1 つも出ない」という
 * 種類の欠陥が起きているため、view data の有無だけでなく実際の描画結果(HTML)まで確認する。
 * `resources/views/settings/_partials/tab-meeting.blade.php` は支給画面のため変更しない。
 */
class GoogleCalendarTabRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_coach_sees_connected_badge_and_disconnect_button(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = GoogleCalendarCredential::factory()->forCoach($coach)->create([
            'calendar_id' => 'primary',
        ]);

        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        $response->assertOk();
        // 連携状態バッジ
        $response->assertSee('連携中', false);
        $response->assertDontSee('未連携', false);
        // 連携日時・calendar_id の表示(コーチ本人の連携情報が出ていること)
        $response->assertSee($credential->calendar_id, false);
        // 解除ボタン(フォームの送信先が destroy ルートであること)
        $response->assertSee('連携を解除する', false);
        $response->assertSee(route('settings.google-calendar.destroy'), false);
        // 未連携時のみ出る連携リンクは出ない
        $response->assertDontSee('Googleカレンダーと連携する', false);
    }

    public function test_unconnected_coach_sees_unconnected_badge_and_connect_link(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        $response->assertOk();
        // 連携状態バッジ
        $response->assertSee('未連携', false);
        $response->assertDontSee('連携中', false);
        // 連携リンク(redirect ルートを指していること)
        $response->assertSee('Googleカレンダーと連携する', false);
        $response->assertSee(route('settings.google-calendar.redirect'), false);
        // 連携済時のみ出る解除ボタンは出ない
        $response->assertDontSee('連携を解除する', false);
    }

    public function test_connected_badge_reflects_other_coachs_own_credential_only(): void
    {
        // 本人の連携状態のみが描画されること(他コーチの連携有無に影響されない)の確認。
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($otherCoach)->create();

        $response = $this->actingAs($coach)->get(route('settings.availability.index'));

        $response->assertOk();
        $response->assertSee('未連携', false);
        $response->assertDontSee('連携中', false);
    }
}
