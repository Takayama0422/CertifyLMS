<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `DatabaseNotification` の認可(要件シート S4: 通知閲覧 / 既読化は本人のみ、他ロールも全員拒否)を検証する。
 */
class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function notificationFor(User $user): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ChatMessageReceivedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['notification_type' => 'chat_message_received', 'title' => 't', 'message' => 'm', 'body' => 'b', 'url' => '/x'],
            'read_at' => null,
        ]);
    }

    public function test_owner_can_view_and_update(): void
    {
        $policy = new NotificationPolicy;
        $owner = User::factory()->student()->create();
        $notification = $this->notificationFor($owner);

        $this->assertTrue($policy->view($owner, $notification));
        $this->assertTrue($policy->update($owner, $notification));
    }

    public function test_other_student_cannot_view_or_update(): void
    {
        $policy = new NotificationPolicy;
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $notification = $this->notificationFor($owner);

        $this->assertFalse($policy->view($other, $notification));
        $this->assertFalse($policy->update($other, $notification));
    }

    public function test_coach_cannot_view_or_update_students_notification(): void
    {
        $policy = new NotificationPolicy;
        $owner = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $notification = $this->notificationFor($owner);

        $this->assertFalse($policy->view($coach, $notification));
        $this->assertFalse($policy->update($coach, $notification));
    }

    public function test_admin_cannot_view_or_update_others_notification(): void
    {
        $policy = new NotificationPolicy;
        $owner = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $notification = $this->notificationFor($owner);

        $this->assertFalse($policy->view($admin, $notification));
        $this->assertFalse($policy->update($admin, $notification));
    }
}
