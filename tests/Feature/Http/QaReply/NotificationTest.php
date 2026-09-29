<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問掲示板の回答投稿(`POST /qa-board/{thread}/replies`)が `qa_reply_received` 通知を
 * スレッド投稿者へ発火することを検証する(要件シート S8)。
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function assignCoach(User $coach, Certification $certification, User $admin): void
    {
        CertificationCoachAssignment::create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_thread_author_receives_notification_when_coach_replies(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);
        $thread = QaThread::factory()->for($certification)->for($author)->create();

        $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '回答です。',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $author->id,
            'type' => QaReplyReceivedNotification::class,
        ]);
    }

    public function test_self_reply_does_not_notify_self(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->for($author)->create();

        $this->actingAs($author)->post(route('qa-board.replies.store', $thread), [
            'body' => '自己補足です。',
        ]);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $author->id]);
    }

    public function test_withdrawn_thread_author_is_excluded_from_notification(): void
    {
        $author = User::factory()->student()->withdrawn()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($coach, $certification, $admin);
        $thread = QaThread::factory()->for($certification)->for($author)->create();

        $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '回答です。',
        ]);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $author->id]);
    }
}
