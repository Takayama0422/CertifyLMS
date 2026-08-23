<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentNotePolicy の判定を検証する Unit テスト。
 * 受講生は全 ability 拒否 / コーチは担当資格のみ閲覧・追加可 / 編集・削除は作成者本人か admin のみを網羅する。
 */
class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_view_or_create(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertFalse($policy->viewAny($student, $enrollment));
        $this->assertFalse($policy->create($student, $enrollment));
    }

    public function test_coach_can_manage_only_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $assignedCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assignedCert->id,
            'user_id' => $coach->id,
        ]);
        $assignedEnrollment = Enrollment::factory()->for($assignedCert)->learning()->create();
        $otherEnrollment = Enrollment::factory()->for($otherCert)->learning()->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($coach, $assignedEnrollment));
        $this->assertTrue($policy->create($coach, $assignedEnrollment));
        $this->assertFalse($policy->viewAny($coach, $otherEnrollment), '担当外資格は閲覧不可');
        $this->assertFalse($policy->create($coach, $otherEnrollment), '担当外資格は追加不可');
    }

    public function test_admin_can_manage_any_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->viewAny($admin, $enrollment));
        $this->assertTrue($policy->create($admin, $enrollment));
    }

    public function test_update_and_delete_allowed_only_for_author_or_admin(): void
    {
        $author = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($author)->create();
        $policy = new EnrollmentNotePolicy;

        $this->assertTrue($policy->update($author, $note));
        $this->assertTrue($policy->delete($author, $note));
        $this->assertFalse($policy->update($otherCoach, $note), '他コーチのメモは編集不可');
        $this->assertFalse($policy->delete($otherCoach, $note), '他コーチのメモは削除不可');
        $this->assertTrue($policy->update($admin, $note), '管理者は全メモを編集可');
        $this->assertTrue($policy->delete($admin, $note), '管理者は全メモを削除可');
    }

    public function test_view_any_excludes_trashed_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $enrollment->delete();
        $trashed = Enrollment::withTrashed()->findOrFail($enrollment->id);
        $policy = new EnrollmentNotePolicy;

        $this->assertFalse($policy->viewAny($admin, $trashed), '親 Enrollment 削除後は一覧から除外');
    }
}
