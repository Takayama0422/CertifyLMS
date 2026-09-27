<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Avatar;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST /settings/avatar` のアップロードを検証する。形式 / サイズの拒否と、差し替え時の旧ファイル削除を担保する。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_upload_png_avatar(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $response->assertSessionHas('success', 'アイコン画像を更新しました。');

        $fresh = $student->fresh();
        $this->assertNotNull($fresh->avatar_url);
        $this->assertStringStartsWith('/storage/avatars/', $fresh->avatar_url);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $fresh->avatar_url), '/'));
    }

    public function test_jpeg_and_webp_are_accepted(): void
    {
        Storage::fake('public');
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->image('avatar.jpg')])
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertNotNull($coach->fresh()->avatar_url);

        $this->actingAs($coach)
            ->post(route('settings.avatar.store'), ['avatar' => UploadedFile::fake()->create('avatar.webp', 100, 'image/webp')])
            ->assertRedirect(route('settings.profile.edit'));
        $this->assertNotNull($coach->fresh()->avatar_url);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('avatar.png', 2049, 'image/png'),
            ]);

        $response->assertSessionHasErrors(['avatar']);
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_rejects_disallowed_mime_type(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $response = $this->actingAs($student)
            ->from(route('settings.profile.edit'))
            ->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('avatar.gif', 100, 'image/gif'),
            ]);

        $response->assertSessionHasErrors(['avatar']);
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_uploading_new_avatar_deletes_previous_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('first.png'),
        ]);
        $firstPath = ltrim(str_replace('/storage/', '', $student->fresh()->avatar_url), '/');
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('second.png'),
        ]);
        $secondPath = ltrim(str_replace('/storage/', '', $student->fresh()->avatar_url), '/');

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * レビュー指摘 12: 修了済受講生のアバター登録が未検証だったため追加する。
     * 設定画面自体は修了済受講生も閲覧できる(`EditTest::test_graduated_student_can_view_own_profile`)ため、
     * アイコン画像アップロードも同様に利用できることを担保する。
     */
    public function test_graduated_student_can_upload_avatar(): void
    {
        Storage::fake('public');
        $graduated = User::factory()->student()->graduated()->create(['avatar_url' => null]);

        $response = $this->actingAs($graduated)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ]);

        $response->assertRedirect(route('settings.profile.edit'));
        $fresh = $graduated->fresh();
        $this->assertNotNull($fresh->avatar_url);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $fresh->avatar_url), '/'));
    }
}
