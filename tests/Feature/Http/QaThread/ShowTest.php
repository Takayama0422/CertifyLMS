<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /qa-board/{thread}` の詳細閲覧を検証する。
 * 観点: 投稿者本人 / 他の受講生 / 担当コーチ / 担当外コーチ / 公開停止資格の非表示 / 管理者は公開画面へアクセス不可。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_own_thread(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        $response = $this->actingAs($author)->get(route('qa-board.show', $thread));

        $response->assertOk();
        $response->assertViewIs('qa-thread.show');
    }

    public function test_other_student_can_view_thread_on_published_certification(): void
    {
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($other)->get(route('qa-board.show', $thread));

        $response->assertOk();
    }

    public function test_student_cannot_view_thread_on_unpublished_certification(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $response = $this->actingAs($student)->get(route('qa-board.show', $thread));

        $response->assertForbidden();
    }

    public function test_assigned_coach_can_view_thread(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert, $admin);
        $thread = QaThread::factory()->for($cert)->create();

        $response = $this->actingAs($coach)->get(route('qa-board.show', $thread));

        $response->assertOk();
    }

    public function test_unassigned_coach_cannot_view_thread(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($coach)->get(route('qa-board.show', $thread));

        $response->assertForbidden();
    }

    public function test_admin_is_forbidden_on_public_show_route(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($admin)->get(route('qa-board.show', $thread));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->get(route('qa-board.show', $thread));

        $response->assertRedirect(route('login'));
    }

    private function assignCoach(User $coach, Certification $certification, User $admin): void
    {
        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
