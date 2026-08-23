<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /settings/profile` の閲覧を検証する。全ロール(修了済受講生を含む)が閲覧できることを担保する。
 */
class EditTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_own_profile(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('settings.profile.edit'));

        $response->assertOk();
        $response->assertViewIs('settings.profile');
        $response->assertViewHas('user', fn (User $user) => $user->is($student));
    }

    public function test_graduated_student_can_view_own_profile(): void
    {
        $graduated = User::factory()->student()->graduated()->create();

        $response = $this->actingAs($graduated)->get(route('settings.profile.edit'));

        $response->assertOk();
    }

    public function test_coach_can_view_own_profile(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('settings.profile.edit'));

        $response->assertOk();
    }

    public function test_admin_can_view_own_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('settings.profile.edit'));

        $response->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('settings.profile.edit'));

        $response->assertRedirect(route('login'));
    }

    /**
     * レビュー指摘 12: 要件は「受講生 / 管理者には固定面談 URL の入力欄が現れず、操作もできない」こと。
     * 従来のテストは送信しても更新されない側しか検証しておらず、画面に欄自体が出ないことを見ていなかった。
     */
    public function test_student_does_not_see_meeting_url_field(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('settings.profile.edit'));

        $response->assertOk();
        $response->assertDontSee('name="meeting_url"', false);
        $response->assertDontSee('固定面談 URL');
    }

    public function test_admin_does_not_see_meeting_url_field(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('settings.profile.edit'));

        $response->assertOk();
        $response->assertDontSee('name="meeting_url"', false);
        $response->assertDontSee('固定面談 URL');
    }

    /**
     * 上記 2 件が「そもそも常に出ない実装だった」場合を検出するための陽性対照(コーチには表示される)。
     */
    public function test_coach_sees_meeting_url_field(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('settings.profile.edit'));

        $response->assertOk();
        $response->assertSee('name="meeting_url"', false);
        $response->assertSee('固定面談 URL');
    }
}
