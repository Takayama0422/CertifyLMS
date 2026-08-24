<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Exceptions\AiChat\AiChatDailyLimitExceededException;
use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\AiChat\StoreConversationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StoreConversationAction の Action 単位テスト。
 *
 * - 教材からの会話は同じ教材で乱立しない(dedupe) / 一般相談は毎回新規作成
 * - enrollment_id の自動解決(学習中の資格を優先)
 * - 会話タイトルの自動生成(教材由来のタイトルは 100 文字を超えない — レビュー指摘 3)
 * - 会話作成時に last_message_at を入れる(レビュー指摘 4)
 * - 初回メッセージが日次上限で失敗した場合、会話作成ごとロールバックする(レビュー指摘 9)
 */
class StoreConversationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key']);
    }

    public function test_creates_general_conversation_with_last_message_at_set(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $result = app(StoreConversationAction::class)($student, null, null);

        $this->assertTrue($result->created);
        $this->assertNotNull($result->conversation->last_message_at);
    }

    public function test_general_conversation_always_creates_new(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        app(StoreConversationAction::class)($student, null, null);
        app(StoreConversationAction::class)($student, null, null);

        $this->assertSame(2, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_reuses_existing_conversation_for_same_section(): void
    {
        [$student, $section] = $this->buildEnrolledSection();

        $first = app(StoreConversationAction::class)($student, $section->id, null);
        $second = app(StoreConversationAction::class)($student, $section->id, null);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->conversation->id, $second->conversation->id);
        $this->assertSame(1, AiChatConversation::query()->where('user_id', $student->id)->count());
    }

    public function test_initial_title_uses_first_40_chars_of_message_without_ellipsis(): void
    {
        // Str::limit は文字数ではなく表示幅(mb_strwidth)で切り詰めるため、半角(幅 1)で検証する。
        $student = User::factory()->student()->inProgress()->create();
        $longMessage = str_repeat('a', 60);

        $result = app(StoreConversationAction::class)($student, null, $longMessage);

        $this->assertSame(str_repeat('a', 40), $result->conversation->title);
        $this->assertLessThanOrEqual(100, mb_strlen($result->conversation->title));
    }

    public function test_initial_title_from_section_does_not_exceed_100_chars(): void
    {
        [$student, $section] = $this->buildEnrolledSection(sectionTitle: str_repeat('教', 95));

        $result = app(StoreConversationAction::class)($student, $section->id, null);

        // Str::limit の既定の挙動(末尾に "..." を付与)のままだと 100 文字の DB カラム上限を超える。
        $this->assertLessThanOrEqual(100, mb_strlen($result->conversation->title));
        $this->assertStringEndsNotWith('...', $result->conversation->title);
    }

    public function test_enrollment_context_uses_learning_enrollment_for_section_certification(): void
    {
        [$student, $section] = $this->buildEnrolledSection();

        $result = app(StoreConversationAction::class)($student, $section->id, null);

        $enrollment = Enrollment::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->status);
        $this->assertSame($enrollment->id, $result->conversation->enrollment_id);
    }

    public function test_enrollment_context_falls_back_to_non_learning_enrollment_for_section_certification(): void
    {
        // enrollments は (user_id, certification_id) が一意のため、同じ資格の Enrollment は必ず 1 件。
        // それが学習中でなくても(例: 学習中止)、教材の文脈としては採用する。
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();

        $failedEnrollment = Enrollment::factory()->for($student)->for($certification)
            ->state(['status' => EnrollmentStatus::Failed->value])
            ->create();

        $result = app(StoreConversationAction::class)($student, $section->id, null);

        $this->assertSame($failedEnrollment->id, $result->conversation->enrollment_id);
    }

    public function test_enrollment_context_falls_back_to_default_enrollment_when_no_section(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $student->update(['default_enrollment_id' => $enrollment->id]);

        $result = app(StoreConversationAction::class)($student->fresh(), null, null);

        $this->assertSame($enrollment->id, $result->conversation->enrollment_id);
    }

    public function test_daily_limit_exceeded_during_initial_message_rolls_back_conversation_creation(): void
    {
        config(['ai-chat.daily_message_limit' => 0]);
        $student = User::factory()->student()->inProgress()->create();

        $this->expectException(AiChatDailyLimitExceededException::class);

        try {
            app(StoreConversationAction::class)($student, null, '初回メッセージ');
        } finally {
            $this->assertDatabaseCount('ai_chat_conversations', 0);
        }
    }

    /**
     * @return array{0: User, 1: Section}
     */
    private function buildEnrolledSection(?string $sectionTitle = null): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $part = Part::factory()->for($certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create(
            $sectionTitle !== null ? ['title' => $sectionTitle] : [],
        );

        Enrollment::factory()->for($student)->for($certification)
            ->state(['status' => EnrollmentStatus::Learning->value])
            ->create();

        return [$student, $section];
    }
}
