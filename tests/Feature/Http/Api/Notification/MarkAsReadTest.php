<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /api/v1/notifications/{notification}/read` の検証。
 * 観点: 本人の通知のみ既読化可 / 他人宛の通知 ID を指定すると 403(漏洩しない) / 冪等性 /
 *       既読化後の未読件数を返す / 未認証は 401(JSON)。
 */
class MarkAsReadTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user, bool $read = false, string $url = 'https://example.test/target'): DatabaseNotification
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
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_owner_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->actingAs($user)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk();
        $response->assertJsonPath('data.id', $notification->id);
        $response->assertJsonPath('unread_count', 0);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_other_users_notification_id_is_rejected_with_403_and_stays_unread(): void
    {
        $owner = User::factory()->student()->create();
        $stranger = User::factory()->student()->create();
        $notification = $this->createNotification($owner);

        $response = $this->actingAs($stranger)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_coach_cannot_mark_students_notification_as_read(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $notification = $this->createNotification($student);

        $response = $this->actingAs($coach)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertForbidden();
    }

    public function test_admin_cannot_mark_other_users_notification_as_read(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->createNotification($student);

        $response = $this->actingAs($admin)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertForbidden();
    }

    public function test_marking_already_read_notification_is_idempotent(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user, read: true);
        $firstReadAt = $notification->read_at;

        $response = $this->actingAs($user)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_unread_count_reflects_remaining_unread_after_marking_one(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);
        $this->createNotification($user);

        $response = $this->actingAs($user)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk();
        $response->assertJsonPath('unread_count', 1);
    }

    public function test_unknown_notification_id_returns_404(): void
    {
        $user = User::factory()->student()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/notifications/'.Str::uuid().'/read');

        $response->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected_with_json_401(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertUnauthorized();
        $this->assertNull($notification->fresh()->read_at);
    }
}
