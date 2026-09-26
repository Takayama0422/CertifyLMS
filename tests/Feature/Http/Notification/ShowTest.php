<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /notifications/{notification}`(S-B-08 で新設)の検証。
 * 観点: 受信者本人のみ閲覧可 / 本文全文を表示 / 閲覧のみで既読化はしない。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function createNotification(User $user): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\AdminAnnouncementNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'notification_type' => 'admin_announcement',
                'title' => '運営からのお知らせ',
                'message' => '短い要約',
                'body' => '本文全文をここに表示します。改行も\n保持されます。',
                'url' => 'https://example.test/notifications/dummy',
            ],
            'read_at' => null,
        ]);
    }

    public function test_owner_can_view_notification_detail(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->actingAs($user)->get(route('notifications.show', $notification));

        $response->assertOk();
        $response->assertSee('運営からのお知らせ');
        $response->assertSee('本文全文をここに表示します。', false);
    }

    public function test_viewing_detail_does_not_mark_as_read(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $this->actingAs($user)->get(route('notifications.show', $notification));

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_other_users_notification_cannot_be_viewed(): void
    {
        $owner = User::factory()->student()->create();
        $stranger = User::factory()->student()->create();
        $notification = $this->createNotification($owner);

        $response = $this->actingAs($stranger)->get(route('notifications.show', $notification));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $user = User::factory()->student()->create();
        $notification = $this->createNotification($user);

        $response = $this->get(route('notifications.show', $notification));

        $response->assertRedirect(route('login'));
    }
}
