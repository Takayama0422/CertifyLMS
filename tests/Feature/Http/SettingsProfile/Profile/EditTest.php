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
}
