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
 * `GET /qa-board` の一覧を検証する。
 * 観点: 受講生は公開中の資格すべて / コーチは担当かつ公開中の資格のみ / 公開停止資格は非表示 /
 * 資格・解決状態・キーワードでの絞り込み / 新着順 / ページネーション。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_threads_from_all_published_certifications(): void
    {
        $student = User::factory()->student()->create();
        $certA = Certification::factory()->published()->create();
        $certB = Certification::factory()->published()->create();
        $threadA = QaThread::factory()->for($certA)->create();
        $threadB = QaThread::factory()->for($certB)->create();

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($threadA, $threadB) {
            $ids = $threads->pluck('id')->all();

            return in_array($threadA->id, $ids, true) && in_array($threadB->id, $ids, true);
        });
    }

    public function test_student_does_not_see_threads_from_unpublished_certifications(): void
    {
        $student = User::factory()->student()->create();
        $draftCert = Certification::factory()->draft()->create();
        $hiddenThread = QaThread::factory()->for($draftCert)->create();

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertOk();
        $response->assertViewHas('threads', fn ($threads) => ! in_array($hiddenThread->id, $threads->pluck('id')->all(), true));
    }

    public function test_coach_sees_only_assigned_and_published_certification_threads(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $assignedCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $assignedCert, $admin);

        $assignedThread = QaThread::factory()->for($assignedCert)->create();
        $otherThread = QaThread::factory()->for($otherCert)->create();

        $response = $this->actingAs($coach)->get(route('qa-board.index'));

        $response->assertOk();
        $response->assertViewHas('threads', function ($threads) use ($assignedThread, $otherThread) {
            $ids = $threads->pluck('id')->all();

            return in_array($assignedThread->id, $ids, true) && ! in_array($otherThread->id, $ids, true);
        });
    }

    public function test_coach_does_not_see_threads_from_assigned_but_unpublished_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $draftCert = Certification::factory()->draft()->create();
        $this->assignCoach($coach, $draftCert, $admin);
        $thread = QaThread::factory()->for($draftCert)->create();

        $response = $this->actingAs($coach)->get(route('qa-board.index'));

        $response->assertViewHas('threads', fn ($threads) => ! in_array($thread->id, $threads->pluck('id')->all(), true));
    }

    public function test_filters_by_certification(): void
    {
        $student = User::factory()->student()->create();
        $certA = Certification::factory()->published()->create();
        $certB = Certification::factory()->published()->create();
        $threadA = QaThread::factory()->for($certA)->create();
        $threadB = QaThread::factory()->for($certB)->create();

        $response = $this->actingAs($student)->get(route('qa-board.index', ['certification_id' => $certA->id]));

        $response->assertViewHas('threads', function ($threads) use ($threadA, $threadB) {
            $ids = $threads->pluck('id')->all();

            return in_array($threadA->id, $ids, true) && ! in_array($threadB->id, $ids, true);
        });
    }

    public function test_filters_by_resolved_status(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $resolved = QaThread::factory()->for($cert)->resolved()->create();
        $open = QaThread::factory()->for($cert)->open()->create();

        $response = $this->actingAs($student)->get(route('qa-board.index', ['status' => 'resolved']));

        $response->assertViewHas('threads', function ($threads) use ($resolved, $open) {
            $ids = $threads->pluck('id')->all();

            return in_array($resolved->id, $ids, true) && ! in_array($open->id, $ids, true);
        });
    }

    public function test_filters_by_keyword_matching_title_or_body(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        $matching = QaThread::factory()->for($cert)->create(['title' => '二分探索木の実装について']);
        $notMatching = QaThread::factory()->for($cert)->create(['title' => '全く関係ない話題']);

        $response = $this->actingAs($student)->get(route('qa-board.index', ['keyword' => '二分探索木']));

        $response->assertViewHas('threads', function ($threads) use ($matching, $notMatching) {
            $ids = $threads->pluck('id')->all();

            return in_array($matching->id, $ids, true) && ! in_array($notMatching->id, $ids, true);
        });
    }

    public function test_keyword_over_100_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('qa-board.index', ['keyword' => str_repeat('あ', 101)]));

        $response->assertSessionHasErrors(['keyword']);
    }

    public function test_pagination_returns_20_per_page(): void
    {
        $student = User::factory()->student()->create();
        $cert = Certification::factory()->published()->create();
        QaThread::factory()->for($cert)->count(25)->create();

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertViewHas('threads', fn ($threads) => $threads->count() === 20 && $threads->total() === 25);
    }

    public function test_admin_is_forbidden_on_public_index(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('qa-board.index'));

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('qa-board.index'));

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
