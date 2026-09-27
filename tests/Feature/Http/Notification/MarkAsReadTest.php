<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /notifications/{notification}/read` の検証。
 * 観点: 本人の通知のみ既読化可 / 他人宛は 403 かつ未読のまま / 既読化と同時に遷移先 URL へ redirect / 冪等性。
 */
class MarkAsReadTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user, string $url = 'https://example.test/target'): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ChatMessageReceivedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'notification_type' => 'chat_message_received',
                'title' => 'テスト通知',
                'message' => '本文プレビュー',
                'body' => '本文全文',
                'url' => $url,
            ],
            'read_at' => null,
        ]);
    }

    public function test_owner_can_mark_own_notification_as_read_and_is_redirected_to_target_url(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user, url: 'https://example.test/qa-board/abc');

        $response = $this->actingAs($user)->post(route('notifications.markAsRead', $notification));

        $response->assertRedirect('https://example.test/qa-board/abc');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_marking_already_read_notification_is_idempotent(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);
        $notification->markAsRead();
        $firstReadAt = $notification->fresh()->read_at;

        $this->actingAs($user)->post(route('notifications.markAsRead', $notification));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_other_users_notification_cannot_be_marked_as_read(): void
    {
        $owner = User::factory()->student()->create();
        $stranger = User::factory()->student()->create();
        $notification = $this->createNotification($owner);

        $response = $this->actingAs($stranger)->post(route('notifications.markAsRead', $notification));

        $response->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_coach_cannot_mark_students_notification_as_read(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $notification = $this->createNotification($student);

        $response = $this->actingAs($coach)->post(route('notifications.markAsRead', $notification));

        $response->assertForbidden();
    }

    public function test_admin_cannot_mark_other_users_notification_as_read(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->createNotification($student);

        $response = $this->actingAs($admin)->post(route('notifications.markAsRead', $notification));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->post(route('notifications.markAsRead', $notification));

        $response->assertRedirect(route('login'));
    }
}
