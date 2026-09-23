<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /notifications/read-all` の検証。
 * 観点: 自分宛の未読すべてを既読化 / 他人宛には影響しない / 一覧へ redirect + フラッシュ文言。
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

    public function test_marks_all_own_unread_notifications_as_read(): void
    {
        $user = User::factory()->student()->create();
        $n1 = $this->createNotification($user);
        $n2 = $this->createNotification($user);
        $alreadyRead = $this->createNotification($user, read: true);

        $response = $this->actingAs($user)->post(route('notifications.markAllAsRead'));

        $response->assertRedirect(route('notifications.index'));
        $response->assertSessionHas('success', 'すべての通知を既読にしました。');
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

        $this->actingAs($user)->post(route('notifications.markAllAsRead'));

        $this->assertNotNull($ownNotification->fresh()->read_at);
        $this->assertNull($othersNotification->fresh()->read_at);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->post(route('notifications.markAllAsRead'));

        $response->assertRedirect(route('login'));
    }
}
