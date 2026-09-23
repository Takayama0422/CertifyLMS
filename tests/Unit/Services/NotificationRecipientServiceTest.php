<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\NotificationRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 通知配信対象の除外規則(要件シート S8: 管理者 / 退会済 / 招待中は対象外)を検証する。
 */
class NotificationRecipientServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_student_is_eligible(): void
    {
        $user = User::factory()->student()->inProgress()->make();

        $this->assertTrue(NotificationRecipientService::eligibleForEventNotification($user));
    }

    public function test_in_progress_coach_is_eligible(): void
    {
        $user = User::factory()->coach()->inProgress()->make();

        $this->assertTrue(NotificationRecipientService::eligibleForEventNotification($user));
    }

    public function test_admin_is_not_eligible(): void
    {
        $user = User::factory()->admin()->inProgress()->make();

        $this->assertFalse(NotificationRecipientService::eligibleForEventNotification($user));
    }

    public function test_withdrawn_user_is_not_eligible(): void
    {
        $user = User::factory()->student()->withdrawn()->make();

        $this->assertFalse(NotificationRecipientService::eligibleForEventNotification($user));
    }

    public function test_invited_user_is_not_eligible(): void
    {
        $user = User::factory()->student()->invited()->make();

        $this->assertFalse(NotificationRecipientService::eligibleForEventNotification($user));
    }

    public function test_graduated_student_is_eligible_for_event_notification(): void
    {
        // お知らせ配信(S-B-08)は修了済を対象外にするが、業務イベント通知(S-B-04)側は
        // 「退会済 / 招待中 / 管理者」のみを除外規則とする(要件シート S12-06)。
        $user = User::factory()->student()->graduated()->make();

        $this->assertTrue(NotificationRecipientService::eligibleForEventNotification($user));
    }
}
