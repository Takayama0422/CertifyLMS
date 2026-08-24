<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AiChatConversation モデルのリレーション・Cast・DB 制約を検証する Unit テスト。
 *
 * - 4 リレーション(user / enrollment / section / messages)
 * - cast(title_manually_set bool / last_message_at datetime)
 * - messages リレーションの並び順(created_at が同一でも id(ULID)で生成順に確定する)
 * - (user_id, section_id) 一意制約(同じ受講生 × 同じ教材の会話を乱立させない)
 */
class AiChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_returns_owner(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $this->assertTrue($conversation->user->is($student));
    }

    public function test_enrollment_relation_returns_context_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $conversation = AiChatConversation::factory()->create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
        ]);

        $this->assertTrue($conversation->enrollment->is($enrollment));
    }

    public function test_section_relation_returns_context_section(): void
    {
        $section = Section::factory()->create();
        $conversation = AiChatConversation::factory()->create(['section_id' => $section->id]);

        $this->assertTrue($conversation->section->is($section));
    }

    public function test_title_manually_set_and_last_message_at_casts(): void
    {
        $conversation = AiChatConversation::factory()->create([
            'title_manually_set' => true,
            'last_message_at' => '2026-05-20 12:00:00',
        ]);

        $fresh = $conversation->fresh();

        $this->assertIsBool($fresh->title_manually_set);
        $this->assertTrue($fresh->title_manually_set);
        $this->assertInstanceOf(Carbon::class, $fresh->last_message_at);
    }

    public function test_messages_relation_orders_by_created_at_then_id_as_tiebreaker(): void
    {
        $conversation = AiChatConversation::factory()->create();
        $sameTimestamp = Carbon::now();

        // 1 リクエスト内で質問 → 回答を連続保存すると created_at が同一秒になるのが常態。
        // ULID(id)はミリ秒精度 + 単調増加な乱数部を持つため、生成順どおりに並ぶはず。
        $generatedFirst = AiChatMessage::factory()->fromUser()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'created_at' => $sameTimestamp,
            'updated_at' => $sameTimestamp,
        ]);
        $generatedSecond = AiChatMessage::factory()->fromAssistant()->create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'created_at' => $sameTimestamp,
            'updated_at' => $sameTimestamp,
        ]);

        $this->assertTrue(
            $generatedFirst->id < $generatedSecond->id,
            'ULID は生成順に単調増加するはず(前提が崩れていないことの確認)',
        );

        $ordered = $conversation->fresh()->messages;

        $this->assertSame($generatedFirst->id, $ordered->first()->id);
        $this->assertSame($generatedSecond->id, $ordered->last()->id);
    }

    public function test_unique_constraint_prevents_duplicate_section_conversation_for_same_user(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $section = Section::factory()->create();

        AiChatConversation::factory()->create(['user_id' => $student->id, 'section_id' => $section->id]);

        $this->expectException(QueryException::class);

        AiChatConversation::factory()->create(['user_id' => $student->id, 'section_id' => $section->id]);
    }

    public function test_unique_constraint_allows_same_section_for_different_users(): void
    {
        $section = Section::factory()->create();
        $studentA = User::factory()->student()->inProgress()->create();
        $studentB = User::factory()->student()->inProgress()->create();

        AiChatConversation::factory()->create(['user_id' => $studentA->id, 'section_id' => $section->id]);
        AiChatConversation::factory()->create(['user_id' => $studentB->id, 'section_id' => $section->id]);

        $this->assertDatabaseCount('ai_chat_conversations', 2);
    }

    public function test_unique_constraint_allows_multiple_general_conversations_with_null_section(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        AiChatConversation::factory()->create(['user_id' => $student->id, 'section_id' => null]);
        AiChatConversation::factory()->create(['user_id' => $student->id, 'section_id' => null]);

        $this->assertSame(2, AiChatConversation::query()->where('user_id', $student->id)->count());
    }
}
