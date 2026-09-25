<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\UserPreference;

use App\Exceptions\UserPreference\AvatarStorageException;
use App\Models\User;
use App\UseCases\UserPreference\StoreAvatarAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * StoreAvatarAction の単体テスト(レビュー指摘 10・11)。
 *
 * - 指摘 11: 保存名の拡張子はクライアント申告値ではなく、検証済みの実データから判定すること。
 * - 指摘 10: DB 更新に失敗した場合、保存済みの新ファイルを孤児として残さないこと。
 */
class StoreAvatarActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stored_extension_is_derived_from_actual_mime_type_not_client_filename(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        // FormRequest の mimes:png,jpg,jpeg,webp が guessExtension() (実データ由来) で通過させる状況を再現する:
        // 実体は image/png だが、クライアントが危険な拡張子のファイル名を申告している
        $file = UploadedFile::fake()->create('avatar.phtml', 10, 'image/png');

        $updated = app(StoreAvatarAction::class)($student, $file);

        $this->assertStringStartsWith('/storage/avatars/', $updated->avatar_url);
        $this->assertStringEndsNotWith('.phtml', $updated->avatar_url);
        $this->assertStringEndsWith('.png', $updated->avatar_url);
    }

    public function test_newly_stored_file_is_deleted_when_db_update_fails(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['avatar_url' => null]);

        $mockUser = Mockery::mock($student)->makePartial();
        $mockUser->shouldReceive('update')->once()->andThrow(new \RuntimeException('db down'));

        $this->expectException(AvatarStorageException::class);

        try {
            app(StoreAvatarAction::class)($mockUser, UploadedFile::fake()->image('avatar.png'));
        } finally {
            $this->assertEmpty(Storage::disk('public')->files('avatars'), '更新失敗時に保存済みファイルが孤児として残っている');
        }
    }

    public function test_previous_file_is_deleted_only_after_successful_replacement(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $first = app(StoreAvatarAction::class)($student, UploadedFile::fake()->image('first.png'));
        $firstPath = ltrim(str_replace('/storage/', '', $first->avatar_url), '/');
        Storage::disk('public')->assertExists($firstPath);

        $second = app(StoreAvatarAction::class)($first, UploadedFile::fake()->image('second.png'));
        $secondPath = ltrim(str_replace('/storage/', '', $second->avatar_url), '/');

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }
}
