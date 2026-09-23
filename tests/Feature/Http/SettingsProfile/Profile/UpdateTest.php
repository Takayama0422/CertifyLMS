<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `PATCH /settings/profile` の更新を検証する。
 * 観点: 正常系(HTML 遷移先 + フラッシュ文言) / 修了済受講生の利用可否 / email 不変 / コーチ限定 meeting_url /
 * 他ユーザー非干渉 / 入力検証の境界値。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_update_name_and_bio(): void
    {
        $student = User::factory()->student()->create(['name' => '旧名前', 'bio' => '旧自己紹介']);

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '新しい名前',
            'bio' => '新しい自己紹介',
        ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'プロフィールを更新しました。');
        $this->assertSame('新しい名前', $student->fresh()->name);
        $this->assertSame('新しい自己紹介', $student->fresh()->bio);
    }

    public function test_graduated_student_can_update_profile(): void
    {
        $graduated = User::factory()->student()->graduated()->create();

        $response = $this->actingAs($graduated)->patch(route('settings.profile.update'), [
            'name' => '修了済 太郎',
            'bio' => '修了後も更新できる',
        ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('修了済 太郎', $graduated->fresh()->name);
    }

    public function test_admin_can_update_own_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('settings.profile.update'), [
            'name' => '管理者 新',
            'bio' => null,
        ]);

        $this->assertSame('管理者 新', $admin->fresh()->name);
    }

    public function test_email_cannot_be_changed(): void
    {
        $student = User::factory()->student()->create(['email' => 'original@certify-lms.test']);

        $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => $student->name,
            'bio' => null,
            'email' => 'changed@certify-lms.test',
        ]);

        $this->assertSame('original@certify-lms.test', $student->fresh()->email);
    }

    public function test_coach_can_update_meeting_url(): void
    {
        $coach = User::factory()->coach()->create(['meeting_url' => null]);

        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'bio' => null,
            'meeting_url' => 'https://meet.google.com/new-room',
        ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertSame('https://meet.google.com/new-room', $coach->fresh()->meeting_url);
    }

    public function test_student_sending_meeting_url_does_not_update_it(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => $student->name,
            'bio' => null,
            'meeting_url' => 'https://meet.google.com/should-be-ignored',
        ]);

        $this->assertNull($student->fresh()->meeting_url);
    }

    public function test_admin_sending_meeting_url_does_not_update_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('settings.profile.update'), [
            'name' => $admin->name,
            'bio' => null,
            'meeting_url' => 'https://meet.google.com/should-be-ignored',
        ]);

        $this->assertNull($admin->fresh()->meeting_url);
    }

    public function test_updating_own_profile_does_not_affect_other_users(): void
    {
        $student = User::factory()->student()->create(['name' => '本人']);
        $other = User::factory()->student()->create(['name' => '他人']);

        $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '本人(更新後)',
            'bio' => null,
        ]);

        $this->assertSame('他人', $other->fresh()->name);
    }

    public function test_name_is_required(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), ['name' => '']);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_name_at_50_characters_is_accepted(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => str_repeat('あ', 50),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('あ', 50), $student->fresh()->name);
    }

    public function test_name_at_51_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), ['name' => str_repeat('あ', 51)]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_bio_at_1000_characters_is_accepted(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => $student->name,
            'bio' => str_repeat('あ', 1000),
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_bio_at_1001_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), [
                'name' => $student->name,
                'bio' => str_repeat('あ', 1001),
            ]);

        $response->assertSessionHasErrors(['bio']);
    }

    public function test_coach_meeting_url_must_be_valid_url_format(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->from(route('settings.profile.edit'))
            ->patch(route('settings.profile.update'), [
                'name' => $coach->name,
                'meeting_url' => 'not-a-url',
            ]);

        $response->assertSessionHasErrors(['meeting_url']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->patch(route('settings.profile.update'), ['name' => '名無し']);

        $response->assertRedirect(route('login'));
    }
}
