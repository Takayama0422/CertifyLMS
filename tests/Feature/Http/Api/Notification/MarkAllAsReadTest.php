<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /api/v1/notifications/read-all` の検証。
 * 観点: 自分宛の未読すべてを既読化(自分宛のみが対象、他人宛には影響しない) /
 *       既読化件数 + 既読化後の未読件数(常に 0)を返す / 未認証は 401(JSON)。
 */
class MarkAllAsReadTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user, bool $read = false): DatabaseNotification
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
                'url' => 'https://example.test/target',
            ],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_marks_all_own_unread_notifications_as_read_and_returns_counts(): void
    {
        $user = User::factory()->student()->create();
        $n1 = $this->createNotification($user);
        $n2 = $this->createNotification($user);
        $alreadyRead = $this->createNotification($user, read: true);

        $response = $this->actingAs($user)->postJson('/api/v1/notifications/read-all');

        $response->assertOk();
        $response->assertJson(['updated_count' => 2, 'unread_count' => 0]);
        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNotNull($n2->fresh()->read_at);
        $this->assertNotNull($alreadyRead->fresh()->read_at);
    }

    public function test_does_not_affect_other_users_notifications(): void
    {
        $user = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $ownNotification = $this->createNotification($user);
        $othersNotification = $this->createNotification($other);

        $this->actingAs($user)->postJson('/api/v1/notifications/read-all');

        $this->assertNotNull($ownNotification->fresh()->read_at);
        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_unauthenticated_request_is_rejected_with_json_401(): void
    {
        $response = $this->postJson('/api/v1/notifications/read-all');

        $response->assertUnauthorized();
    }
}
