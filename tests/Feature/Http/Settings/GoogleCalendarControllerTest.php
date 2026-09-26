<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleCalendarClient;
use Tests\TestCase;

/**
 * S-A-01: Google カレンダー連携の HTTP エントリポイント(`GoogleCalendarController`)を検証する。
 *
 * 実通信は `FakeGoogleCalendarClient` に完全に差し替え、実通信を一切発生させない。
 * コーチ以外のロールが弾かれること、OAuth state 検証(なりすまし拒否)、連携解除の成否を確認する。
 */
class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeGoogleCalendarClient
    {
        $fake = new FakeGoogleCalendarClient;
        $this->app->instance(GoogleCalendarClient::class, $fake);

        return $fake;
    }

    // ---- 認可: コーチのみ ----

    public function test_guest_is_redirected_to_login_on_redirect_route(): void
    {
        $this->fake();

        $response = $this->get(route('settings.google-calendar.redirect'));

        $response->assertRedirect(route('login'));
    }

    public function test_student_is_forbidden_on_redirect_route(): void
    {
        $this->fake();
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('settings.google-calendar.redirect'));

        $response->assertForbidden();
    }

    public function test_admin_is_forbidden_on_redirect_route(): void
    {
        $this->fake();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('settings.google-calendar.redirect'));

        $response->assertForbidden();
    }

    public function test_student_is_forbidden_on_destroy_route(): void
    {
        $this->fake();
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->delete(route('settings.google-calendar.destroy'));

        $response->assertForbidden();
    }

    public function test_student_is_forbidden_on_callback_route(): void
    {
        $this->fake();
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('settings.google-calendar.callback', ['code' => 'x', 'state' => 'y']));

        $response->assertForbidden();
    }

    // ---- redirect ----

    public function test_coach_redirect_sends_to_google_authorization_url(): void
    {
        $fake = $this->fake();
        $fake->authorizationUrlResult = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=fake';
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('settings.google-calendar.redirect'));

        $response->assertRedirect('https://accounts.google.com/o/oauth2/v2/auth?client_id=fake');
        $this->assertCount(1, $fake->authorizationUrlCalls);
    }

    public function test_coach_redirect_rejects_open_redirect_attempt(): void
    {
        $fake = $this->fake();
        $coach = User::factory()->coach()->create();

        // オープンリダイレクト攻撃を模した redirect_path(スキーム相対 URL)
        $response = $this->actingAs($coach)->get(route('settings.google-calendar.redirect', ['redirect_path' => '//evil.example.com']));

        $response->assertSessionHas('google_calendar.oauth_redirect_path', '/settings/availability');
    }

    // ---- callback ----

    public function test_callback_rejects_request_without_prior_authorization(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('settings.google-calendar.callback', [
            'code' => 'auth-code',
            'state' => 'forged-state',
        ]));

        // state 検証(なりすまし対策)に失敗するため 403
        $response->assertForbidden();
        $this->assertDatabaseMissing('google_calendar_credentials', ['user_id' => $coach->id]);
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar.oauth_state' => 'expected-state',
                'google_calendar.oauth_user_id' => $coach->id,
            ])
            ->get(route('settings.google-calendar.callback', [
                'code' => 'auth-code',
                'state' => 'forged-state',
            ]));

        $response->assertForbidden();
        $this->assertDatabaseMissing('google_calendar_credentials', ['user_id' => $coach->id]);
    }

    /**
     * S-A-01 コードレビュー指摘対応: なりすまし検証より前にセッションを消していた欠陥の回帰テスト。
     *
     * 偽のコールバック(state 不一致)を投げても、進行中の正規フローの遷移先(`oauth_redirect_path`)が
     * セッションから消えないこと(=検証がセッション変更より先に行われていること)を検証する。
     * 従来は state 検証結果に関わらず遷移先を無条件にセッションから取り出していたため、
     * この偽コールバックが 403 で拒否される(連携自体は成立しない)一方で、遷移先だけが失われていた。
     */
    public function test_callback_with_forged_state_does_not_consume_pending_redirect_path(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar.oauth_state' => 'expected-state',
                'google_calendar.oauth_user_id' => $coach->id,
                'google_calendar.oauth_redirect_path' => '/settings/availability',
            ])
            ->get(route('settings.google-calendar.callback', [
                'code' => 'auth-code',
                'state' => 'forged-state',
            ]));

        $response->assertForbidden();
        // 偽コールバックで拒否された後も、進行中の正規フローの遷移先は消えていない
        $response->assertSessionHas('google_calendar.oauth_redirect_path', '/settings/availability');
        // state / user も同様に(まだ本物のコールバックが来ていないため)保持されたまま
        $response->assertSessionHas('google_calendar.oauth_state', 'expected-state');
        $response->assertSessionHas('google_calendar.oauth_user_id', $coach->id);
    }

    public function test_callback_rejects_when_session_belongs_to_different_coach(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        // otherCoach の認可フローで発行された state を coach が横取りしたケース
        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar.oauth_state' => 'shared-state',
                'google_calendar.oauth_user_id' => $otherCoach->id,
            ])
            ->get(route('settings.google-calendar.callback', [
                'code' => 'auth-code',
                'state' => 'shared-state',
            ]));

        $response->assertForbidden();
    }

    public function test_callback_succeeds_with_matching_state(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession([
                'google_calendar.oauth_state' => 'valid-state',
                'google_calendar.oauth_user_id' => $coach->id,
                'google_calendar.oauth_redirect_path' => '/settings/availability',
            ])
            ->get(route('settings.google-calendar.callback', [
                'code' => 'auth-code',
                'state' => 'valid-state',
            ]));

        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('google_calendar_credentials', ['user_id' => $coach->id, 'calendar_id' => 'primary']);
    }

    public function test_callback_handles_google_side_denial_without_403(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->withSession(['google_calendar.oauth_redirect_path' => '/settings/availability'])
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied']));

        // ユーザーが Google 側で同意を拒否しただけなので、なりすましとして 403 にはしない
        $response->assertRedirect('/settings/availability');
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('google_calendar_credentials', ['user_id' => $coach->id]);
    }

    // ---- destroy ----

    public function test_coach_can_disconnect_own_credential(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();
        GoogleCalendarCredential::factory()->forCoach($coach)->create();

        $response = $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'));

        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('google_calendar_credentials', ['user_id' => $coach->id]);
    }

    public function test_disconnect_is_noop_when_not_connected(): void
    {
        $this->fake();
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'));

        $response->assertRedirect(route('settings.availability.index'));
    }
}
