<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AiChatConversationPolicy の Unit テスト。
 *
 * - viewAny / create: 学習中(in_progress)の受講生のみ
 * - view / update / delete / sendMessage: 学習中の受講生 かつ 会話オーナー本人のみ
 *   (状態判定を怠ると、受講中に作成した会話を修了後も直リンクで操作し続けられてしまう)
 */
class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_any_allows_in_progress_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->assertTrue(app(AiChatConversationPolicy::class)->viewAny($student));
    }

    public function test_view_any_denies_graduated_student(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->assertFalse(app(AiChatConversationPolicy::class)->viewAny($student));
    }

    public function test_view_any_denies_non_student(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->assertFalse(app(AiChatConversationPolicy::class)->viewAny($coach));
    }

    public function test_create_allows_in_progress_student(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->assertTrue(app(AiChatConversationPolicy::class)->create($student));
    }

    public function test_create_denies_graduated_student(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->assertFalse(app(AiChatConversationPolicy::class)->create($student));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function ownerOnlyMethods(): array
    {
        return [
            ['view'],
            ['update'],
            ['delete'],
            ['sendMessage'],
        ];
    }

    #[DataProvider('ownerOnlyMethods')]
    public function test_owner_only_method_allows_in_progress_owner(string $method): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue(app(AiChatConversationPolicy::class)->{$method}($owner, $conversation));
    }

    #[DataProvider('ownerOnlyMethods')]
    public function test_owner_only_method_denies_other_student(string $method): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $stranger = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->assertFalse(app(AiChatConversationPolicy::class)->{$method}($stranger, $conversation));
    }

    #[DataProvider('ownerOnlyMethods')]
    public function test_owner_only_method_denies_graduated_owner(string $method): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $owner->update(['status' => UserStatus::Graduated->value]);

        $this->assertFalse(app(AiChatConversationPolicy::class)->{$method}($owner->fresh(), $conversation));
    }

    #[DataProvider('ownerOnlyMethods')]
    public function test_owner_only_method_denies_non_student_owner(string $method): void
    {
        // 通常業務では起こらない組み合わせだが、Policy 単体としてロール判定も効くことを確認する。
        $owner = User::factory()->coach()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $owner->id]);

        $this->assertFalse(app(AiChatConversationPolicy::class)->{$method}($owner, $conversation));
    }
}
