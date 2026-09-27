<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\ChatMessage;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S-B-04 の 4 種の業務イベント通知が、いずれも「アプリ内 + メール」の両チャネルで
 * 配信される設定になっていることを検証する(要件シート S8)。
 */
class BusinessEventNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_message_received_uses_database_and_mail(): void
    {
        $sender = User::factory()->student()->create();
        $message = ChatMessage::factory()->create(['sender_user_id' => $sender->id]);

        $notification = new ChatMessageReceivedNotification($message);

        $this->assertSame(['database', 'mail'], $notification->via($sender));
        $this->assertArrayHasKey('notification_type', $notification->toArray($sender));
        $this->assertSame('chat_message_received', $notification->toArray($sender)['notification_type']);
    }

    public function test_qa_reply_received_uses_database_and_mail(): void
    {
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->for($thread, 'thread')->create();

        $notification = new QaReplyReceivedNotification($reply);

        $this->assertSame(['database', 'mail'], $notification->via($thread->user));
        $this->assertSame('qa_reply_received', $notification->toArray($thread->user)['notification_type']);
    }

    public function test_meeting_reserved_uses_database_and_mail(): void
    {
        $meeting = Meeting::factory()->reserved()->create();

        $notification = new MeetingReservedNotification($meeting);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->student));
        $this->assertSame('meeting_reserved', $notification->toArray($meeting->student)['notification_type']);
    }

    public function test_meeting_canceled_uses_database_and_mail(): void
    {
        $meeting = Meeting::factory()->canceled()->create();

        $notification = new MeetingCanceledNotification($meeting);

        $this->assertSame(['database', 'mail'], $notification->via($meeting->coach));
        $this->assertSame('meeting_canceled', $notification->toArray($meeting->coach)['notification_type']);
    }

    public function test_data_payload_has_all_keys_required_by_shipped_views(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $data = (new MeetingReservedNotification($meeting))->toArray($meeting->student);

        foreach (['notification_type', 'title', 'message', 'body', 'url'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
