<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\CertificatePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CertificatePolicy::download を検証する Unit テスト。
 * admin: 全件可 / student: 本人のみ可(ステータス不問) / coach: 担当資格のみ可。
 */
class CertificatePolicyTest extends TestCase
{
    use RefreshDatabase;

    private CertificatePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CertificatePolicy;
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = Certificate::factory()->create();

        $this->assertTrue($this->policy->download($admin, $certificate));
    }

    public function test_student_can_download_own_certificate(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertTrue($this->policy->download($student, $certificate));
    }

    public function test_student_cannot_download_other_students_certificate(): void
    {
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertFalse($this->policy->download($otherStudent, $certificate));
    }

    public function test_student_can_download_own_certificate_regardless_of_current_enrollment_status(): void
    {
        // 修了証は永続資産のため、Enrollment の現在のステータスに関わらずダウンロード可(本人であれば)。
        $student = User::factory()->student()->graduated()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertTrue($this->policy->download($student, $certificate));
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $enrollment = Enrollment::factory()->for($certification)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertTrue($this->policy->download($coach, $certificate));
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        // 「担当を1件も持っていないコーチ」だけでなく、「別の資格を担当しているコーチ」でも弾かれることを検証する。
        // 前者だけだと、認可判定から資格の絞り込みが抜けて「何かしら担当を持つコーチなら誰でも可」になっても
        // テストが通ってしまう(資格による絞り込みそのものは検証できていない)。
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assignedCertification = Certification::factory()->published()->create();
        $assignedCertification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $otherCertification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($otherCertification)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertFalse($this->policy->download($coach, $certificate));
    }

    public function test_coach_with_revoked_assignment_cannot_download_certificate(): void
    {
        // 担当解除済み(unassigned_at 有り)のコーチは、同一資格であっても弾かれることを検証する。
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now()->subDays(10),
            'unassigned_at' => now()->subDay(),
        ]);
        $enrollment = Enrollment::factory()->for($certification)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $this->assertFalse($this->policy->download($coach, $certificate));
    }
}
