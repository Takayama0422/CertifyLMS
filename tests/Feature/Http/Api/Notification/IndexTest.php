<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/v1/notifications` の検証(S-A-05, TopBar 通知ポップオーバー用 JSON API)。
 * 観点: 自分宛のみ返す / 未読件数(バッジ用)は表示件数の上限と無関係に実数を返す /
 *       表示は最新 10 件まで(要件シート S12-13) / 未認証は 401(JSON) / 管理者もロールで弾かれない(S4: 本人のみ) /
 *       本文プレビューは支給 Blade と同じ `message` → `body_preview` の順で解決する(S4)。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user, bool $read = false, ?\DateTimeInterface $createdAt = null): DatabaseNotification
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
        $this->createNotification($user);
        $this->createNotification($other);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_response_shape_contains_expected_fields(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJson([
            'data' => [
                [
                    'id' => $notification->id,
                    'type' => 'chat_message_received',
                    'title' => 'テスト通知',
                    'message' => '本文プレビュー',
                    'url' => 'https://example.test/target',
                    'read_at' => null,
                ],
            ],
            'unread_count' => 1,
        ]);
        $response->assertJsonStructure(['data' => [['created_at', 'created_at_human']]]);
    }

    public function test_message_falls_back_to_body_preview_like_the_supplied_row(): void
    {
        // 要件シート S4 は `data` の本文プレビューを「`message`(または `body_preview`)」と定めており、
        // 支給 Blade `notifications/_partials/notification-row.blade.php` も message → body_preview の順に読む。
        // API 側だけ body_preview を読み落とすと、フルページには本文が出るのにポップオーバーだけ空行になる。
        $user = User::factory()->student()->create();

        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\AdminAnnouncementNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'notification_type' => 'admin_announcement',
                'title' => 'お知らせ',
                'body_preview' => 'body_preview だけを持つ通知',
                'body' => '本文全文',
                'url' => 'https://example.test/announcement',
            ],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonPath('data.0.message', 'body_preview だけを持つ通知');
    }

    public function test_display_is_limited_to_latest_ten_but_unread_count_is_not(): void
    {
        $user = User::factory()->student()->create();
        foreach (range(1, 15) as $i) {
            $this->createNotification($user, read: false, createdAt: now()->subMinutes($i));
        }

        $response = $this->actingAs($user)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('unread_count', 15);
    }

    public function test_unauthenticated_request_is_rejected_with_json_401(): void
    {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertUnauthorized();
    }

    public function test_admin_is_not_role_blocked_and_sees_only_own_notifications(): void
    {
        // S-A-05 の「管理者は対象外」はポップオーバー UI の話であり(要件シート S4 は
        // S-A-05 行で「ポップオーバー表示: ×」とする一方、S-B-04 行では通知閲覧/既読化を
        // 3 ロール共通「本人のみ」としている)、API 自体はロールで弾かない。
        $admin = User::factory()->admin()->create();
        $this->createNotification($admin);

        $response = $this->actingAs($admin)->getJson('/api/v1/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }
}
