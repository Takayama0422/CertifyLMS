<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /notifications` の検証。
 * 観点: 自分宛のみ表示 / 未読タブでの絞り込み / ページネーション / 未認証は login へ redirect。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user, bool $read, ?\DateTimeInterface $createdAt = null): DatabaseNotification
    {
        $createdAt ??= now();

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
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_user_sees_only_own_notifications(): void
    {
        $user = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $this->createNotification($user, read: false);
        $this->createNotification($other, read: false);

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications) {
            return $notifications->total() === 1;
        });
    }

    public function test_unread_tab_filters_to_unread_only(): void
    {
        $user = User::factory()->student()->create();
        $this->createNotification($user, read: true);
        $this->createNotification($user, read: false);

        $response = $this->actingAs($user)->get(route('notifications.index', ['tab' => 'unread']));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications) {
            return $notifications->total() === 1;
        });
        $response->assertViewHas('unreadCount', 1);
    }

    public function test_pagination_limits_to_twenty_per_page(): void
    {
        $user = User::factory()->student()->create();
        foreach (range(1, 25) as $i) {
            $this->createNotification($user, read: false, createdAt: now()->subMinutes($i));
        }

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications) {
            return $notifications->count() === 20 && $notifications->hasMorePages();
        });
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('notifications.index'));

        $response->assertRedirect(route('login'));
    }
}
