<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QaThreadPolicy の単体テスト。受講生 / コーチ / 管理者 × 閲覧・投稿・編集・削除・解決の組み合わせを
 * 網羅する(レビュー指摘 4)。可視性ルール(公開停止中の資格・コーチの担当外し)も併せて検証する。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QaThreadPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new QaThreadPolicy;
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $admin = User::factory()->admin()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }

    // --- viewAny ---

    public function test_view_any_allows_student_coach_and_admin(): void
    {
        $this->assertTrue($this->policy->viewAny(User::factory()->student()->create()));
        $this->assertTrue($this->policy->viewAny(User::factory()->coach()->create()));
        $this->assertTrue($this->policy->viewAny(User::factory()->admin()->create()));
    }

    // --- view ---

    public function test_admin_can_view_thread_regardless_of_certification_status(): void
    {
        $admin = User::factory()->admin()->create();
        $publishedThread = QaThread::factory()->for(Certification::factory()->published())->create();
        $draftThread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $this->assertTrue($this->policy->view($admin, $publishedThread));
        $this->assertTrue($this->policy->view($admin, $draftThread));
    }

    public function test_student_can_view_thread_on_published_certification_regardless_of_ownership(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertTrue($this->policy->view($student, $thread));
    }

    public function test_student_cannot_view_thread_on_unpublished_certification(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $this->assertFalse($this->policy->view($student, $thread));
    }

    public function test_assigned_coach_can_view_thread_on_published_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);
        $thread = QaThread::factory()->for($certification)->create();

        $this->assertTrue($this->policy->view($coach, $thread));
    }

    public function test_assigned_coach_cannot_view_thread_on_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->draft()->create();
        $this->assignCoach($certification, $coach);
        $thread = QaThread::factory()->for($certification)->create();

        $this->assertFalse($this->policy->view($coach, $thread));
    }

    public function test_unassigned_coach_cannot_view_thread(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertFalse($this->policy->view($coach, $thread));
    }

    // --- create ---

    public function test_only_student_can_create_thread(): void
    {
        $this->assertTrue($this->policy->create(User::factory()->student()->create()));
        $this->assertFalse($this->policy->create(User::factory()->coach()->create()));
        $this->assertFalse($this->policy->create(User::factory()->admin()->create()));
    }

    // --- update ---

    public function test_owner_student_can_update_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($student)->create();

        $this->assertTrue($this->policy->update($student, $thread));
    }

    public function test_owner_student_cannot_update_thread_on_unpublished_certification(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->for($student)->create();

        $this->assertFalse($this->policy->update($student, $thread));
    }

    public function test_non_owner_student_cannot_update_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertFalse($this->policy->update($student, $thread));
    }

    public function test_coach_and_admin_cannot_update_thread(): void
    {
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);
        $admin = User::factory()->admin()->create();

        $this->assertFalse($this->policy->update($coach, $thread));
        $this->assertFalse($this->policy->update($admin, $thread));
    }

    // --- delete ---

    public function test_owner_student_can_delete_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($student)->create();

        $this->assertTrue($this->policy->delete($student, $thread));
    }

    public function test_owner_student_cannot_delete_thread_on_unpublished_certification(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->for($student)->create();

        $this->assertFalse($this->policy->delete($student, $thread));
    }

    public function test_non_owner_student_cannot_delete_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertFalse($this->policy->delete($student, $thread));
    }

    public function test_coach_cannot_delete_thread(): void
    {
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);

        $this->assertFalse($this->policy->delete($coach, $thread));
    }

    public function test_admin_can_delete_thread_regardless_of_certification_status(): void
    {
        $admin = User::factory()->admin()->create();
        $publishedThread = QaThread::factory()->for(Certification::factory()->published())->create();
        $draftThread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $this->assertTrue($this->policy->delete($admin, $publishedThread));
        $this->assertTrue($this->policy->delete($admin, $draftThread));
    }

    // --- resolve / unresolve ---

    public function test_owner_student_can_resolve_and_unresolve_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($student)->create();

        $this->assertTrue($this->policy->resolve($student, $thread));
        $this->assertTrue($this->policy->unresolve($student, $thread));
    }

    public function test_owner_student_cannot_resolve_or_unresolve_thread_on_unpublished_certification(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->for($student)->create();

        $this->assertFalse($this->policy->resolve($student, $thread));
        $this->assertFalse($this->policy->unresolve($student, $thread));
    }

    public function test_non_owner_coach_and_admin_cannot_resolve_or_unresolve_thread(): void
    {
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);
        $admin = User::factory()->admin()->create();

        $this->assertFalse($this->policy->resolve($coach, $thread));
        $this->assertFalse($this->policy->unresolve($coach, $thread));
        $this->assertFalse($this->policy->resolve($admin, $thread));
        $this->assertFalse($this->policy->unresolve($admin, $thread));
    }

    // --- moderateDelete ---

    public function test_only_admin_can_moderate_delete_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($student)->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse($this->policy->moderateDelete($student, $thread));
        $this->assertFalse($this->policy->moderateDelete($coach, $thread));
        $this->assertTrue($this->policy->moderateDelete($admin, $thread));
    }
}
