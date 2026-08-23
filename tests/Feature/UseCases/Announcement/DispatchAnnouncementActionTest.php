<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * お知らせ配信ユースケースの検証。
 * 観点: 配信対象 3 種それぞれの宛先解決 / 配信対象外(退会済・招待中・修了済・コーチ・管理者)の除外 /
 * dispatched_count の正確性 / 配信履歴の作成。
 */
class DispatchAnnouncementActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): DispatchAnnouncementAction
    {
        return app(DispatchAnnouncementAction::class);
    }

    public function test_all_students_target_reaches_all_in_progress_students_only(): void
    {
        $admin = User::factory()->admin()->create();
        $eligible1 = User::factory()->student()->inProgress()->create();
        $eligible2 = User::factory()->student()->inProgress()->create();
        $withdrawn = User::factory()->student()->withdrawn()->create();
        $invited = User::factory()->student()->invited()->create();
        $graduated = User::factory()->student()->graduated()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $announcement = $this->action()($admin, [
            'title' => '全員向けお知らせ',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
        ]);

        $this->assertSame(2, $announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $eligible1->id, 'type' => AdminAnnouncementNotification::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $eligible2->id, 'type' => AdminAnnouncementNotification::class]);
        foreach ([$withdrawn, $invited, $graduated, $coach] as $excluded) {
            $this->assertDatabaseMissing('notifications', ['notifiable_id' => $excluded->id]);
        }
    }

    public function test_certification_target_reaches_only_students_enrolled_in_that_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $enrolled = User::factory()->student()->inProgress()->create();
        Enrollment::factory()->for($enrolled, 'user')->for($certification)->learning()->create();

        $notEnrolled = User::factory()->student()->inProgress()->create();
        Enrollment::factory()->for($notEnrolled, 'user')->for($otherCertification)->learning()->create();

        $announcement = $this->action()($admin, [
            'title' => '資格指定お知らせ',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
        ]);

        $this->assertSame(1, $announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $enrolled->id, 'type' => AdminAnnouncementNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $notEnrolled->id]);
    }

    public function test_user_target_reaches_only_the_specified_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();

        $announcement = $this->action()($admin, [
            'title' => '個別お知らせ',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::User->value,
            'target_certification_id' => null,
            'target_user_id' => $target->id,
        ]);

        $this->assertSame(1, $announcement->dispatched_count);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $target->id, 'type' => AdminAnnouncementNotification::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $other->id]);
    }

    public function test_creates_announcement_record_even_when_no_recipients_match(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();

        $announcement = $this->action()($admin, [
            'title' => '対象者なしお知らせ',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certification->id,
            'target_user_id' => null,
        ]);

        $this->assertSame(0, $announcement->dispatched_count);
        $this->assertDatabaseHas('announcements', ['id' => $announcement->id]);
    }
}
