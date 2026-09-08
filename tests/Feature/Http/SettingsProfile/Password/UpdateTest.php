<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Password;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `PUT /settings/password` のパスワード変更を検証する。
 * 現在のパスワード確認・共通パスワード規則・`updatePassword` 名前付きエラーバッグ(Blade 側の
 * `$errors->updatePassword` 参照と対になる)を担保する。全ロール(修了済受講生を含む)が対象。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_change_password_with_correct_current_password(): void
    {
        $student = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));
        $response->assertSessionHas('success', 'パスワードを変更しました。');
        $this->assertTrue(Hash::check('new-secure-password', $student->fresh()->password));
    }

    public function test_graduated_student_can_change_password(): void
    {
        $graduated = User::factory()->student()->graduated()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($graduated)->put(route('settings.password.update'), [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $this->assertTrue(Hash::check('new-secure-password', $graduated->fresh()->password));
    }

    public function test_coach_and_admin_can_change_password(): void
    {
        $coach = User::factory()->coach()->create(['password' => Hash::make('old-password')]);
        $admin = User::factory()->admin()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($coach)->put(route('settings.password.update'), [
            'current_password' => 'old-password',
            'password' => 'coach-new-password',
            'password_confirmation' => 'coach-new-password',
        ]);
        $this->assertTrue(Hash::check('coach-new-password', $coach->fresh()->password));

        $this->actingAs($admin)->put(route('settings.password.update'), [
            'current_password' => 'old-password',
            'password' => 'admin-new-password',
            'password_confirmation' => 'admin-new-password',
        ]);
        $this->assertTrue(Hash::check('admin-new-password', $admin->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected_with_named_error_bag(): void
    {
        $student = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertSessionHasErrors(['current_password'], null, 'updatePassword');
        $this->assertTrue(Hash::check('old-password', $student->fresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $student = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'old-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'different-password',
            ]);

        $response->assertSessionHasErrors(['password'], null, 'updatePassword');
        $this->assertTrue(Hash::check('old-password', $student->fresh()->password));
    }

    public function test_password_shorter_than_minimum_is_rejected(): void
    {
        $student = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit', ['tab' => 'password']))
            ->put(route('settings.password.update'), [
                'current_password' => 'old-password',
                'password' => 'short1',
                'password_confirmation' => 'short1',
            ]);

        $response->assertSessionHasErrors(['password'], null, 'updatePassword');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->put(route('settings.password.update'), [
            'current_password' => 'x',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect(route('login'));
    }
}
