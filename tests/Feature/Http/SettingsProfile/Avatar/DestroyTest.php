<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `DELETE /settings/avatar` の削除を検証する。削除後は未設定表示(avatar_url = null)に戻ることを担保する。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_delete_own_avatar(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();
        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);
        $path = ltrim(str_replace('/storage/', '', $student->fresh()->avatar_url), '/');

        $response = $this->actingAs($student)->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'アイコン画像を削除しました。');
        $this->assertNull($student->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_when_no_avatar_set_is_a_no_op(): void
    {
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $response = $this->actingAs($student)->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('login'));
    }

    /**
     * レビュー指摘 12: 修了済受講生のアバター削除が未検証だったため追加する。
     */
    public function test_graduated_student_can_delete_own_avatar(): void
    {
        Storage::fake('public');
        $graduated = User::factory()->student()->graduated()->create();
        $this->actingAs($graduated)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);
        $path = ltrim(str_replace('/storage/', '', $graduated->fresh()->avatar_url), '/');

        $response = $this->actingAs($graduated)->delete(route('settings.avatar.destroy'));

        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($graduated->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($path);
    }
}
