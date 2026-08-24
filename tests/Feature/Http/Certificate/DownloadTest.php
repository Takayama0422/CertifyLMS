<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /certificates/{certificate}/download の認可・配信を検証する。
 *
 * 認可: 本人(student) / 担当コーチ(coach) / 管理者(admin、全件)。
 * Enrollment / User のステータスは問わない(修了証は永続資産)。
 * PDF ファイルが private disk に存在しない場合は 404。
 */
class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    private function certificateWithFile(User $student, ?Certification $certification = null): Certificate
    {
        $certification ??= Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.7 dummy content');

        return $certificate;
    }

    public function test_student_can_download_own_certificate(): void
    {
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($student);

        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_student_cannot_download_other_students_certificate(): void
    {
        Storage::fake('private');
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($owner);

        $response = $this->actingAs($otherStudent)->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_graduated_student_can_still_download_own_certificate(): void
    {
        // 修了証は永続資産のため、学習中以外(修了)の本人もダウンロード可能。
        Storage::fake('private');
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->certificateWithFile($student);

        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        $response->assertOk();
    }

    public function test_assigned_coach_can_download_certificate(): void
    {
        Storage::fake('private');
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($student, $certification);

        $response = $this->actingAs($coach)->get(route('certificates.download', $certificate));

        $response->assertOk();
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        Storage::fake('private');
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($student);

        $response = $this->actingAs($coach)->get(route('certificates.download', $certificate));

        $response->assertForbidden();
    }

    public function test_admin_can_download_any_certificate(): void
    {
        Storage::fake('private');
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($student);

        $response = $this->actingAs($admin)->get(route('certificates.download', $certificate));

        $response->assertOk();
    }

    public function test_returns_not_found_when_pdf_file_is_missing_from_storage(): void
    {
        Storage::fake('private');
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        // ファイル実体を Storage へ書き込まない(レコードのみ存在)
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($admin)->get(route('certificates.download', $certificate));

        $response->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Storage::fake('private');
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->certificateWithFile($student);

        $response = $this->get(route('certificates.download', $certificate));

        $response->assertRedirect(route('login'));
    }
}
