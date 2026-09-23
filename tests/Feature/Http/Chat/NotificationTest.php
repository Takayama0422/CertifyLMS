<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Chat;

use App\Models\Certification;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * chat メッセージ送信(`POST /chat-rooms/{room}/messages`)が `chat_message_received` 通知を
 * 発火することを検証する(要件シート S8)。受信者は送信者以外のルーム参加者(相手方)。
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function setUpRoom(User $student, User $coach, User $admin): ChatRoom
    {
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);

        return $room;
    }

    public function test_recipient_receives_notification_when_message_is_sent(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $room = $this->setUpRoom($student, $coach, $admin);

        $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => 'こんにちは']);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $coach->id,
            'type' => ChatMessageReceivedNotification::class,
        ]);
    }

    public function test_sender_does_not_receive_self_notification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $room = $this->setUpRoom($student, $coach, $admin);

        $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => 'こんにちは']);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_withdrawn_member_is_excluded_from_notification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $room = $this->setUpRoom($student, $coach, $admin);

        // 退会済ユーザーが通知対象から外れることを、ルームに直接メンバー追加して確認する
        $withdrawn = User::factory()->student()->withdrawn()->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $withdrawn->id]);

        $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => 'こんにちは']);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $withdrawn->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $coach->id]);
    }

    public function test_invited_member_is_excluded_from_notification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $room = $this->setUpRoom($student, $coach, $admin);

        $invited = User::factory()->student()->invited()->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $invited->id]);

        $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => 'こんにちは']);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $invited->id]);
    }

    public function test_admin_member_is_excluded_from_notification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->inProgress()->create();
        $room = $this->setUpRoom($student, $coach, $admin);

        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $admin->id]);

        $this->actingAs($student)->post(route('chat.storeMessage', $room), ['body' => 'こんにちは']);

        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $admin->id]);
    }
}
