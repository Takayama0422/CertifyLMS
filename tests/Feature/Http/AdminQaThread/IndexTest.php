<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AdminQaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /admin/qa-board` の管理者モデレーション一覧を検証する。
 * 観点: 公開停止中の資格を含む全件を横断閲覧できる / 受講生・コーチはアクセス不可。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_threads_from_unpublished_certifications_too(): void
    {
        $admin = User::factory()->admin()->create();
        $draftCert = Certification::factory()->draft()->create();
        $archivedCert = Certification::factory()->archived()->create();
        $draftThread = QaThread::factory()->for($draftCert)->create();
        $archivedThread = QaThread::factory()->for($archivedCert)->create();

        $response = $this->actingAs($admin)->get(route('admin.qa-board.index'));

        $response->assertOk();
        $response->assertViewIs('qa-thread.index');
        $response->assertViewHas('threads', function ($threads) use ($draftThread, $archivedThread) {
            $ids = $threads->pluck('id')->all();

            return in_array($draftThread->id, $ids, true) && in_array($archivedThread->id, $ids, true);
        });
    }

    public function test_student_is_forbidden(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('admin.qa-board.index'));

        $response->assertForbidden();
    }

    public function test_coach_is_forbidden(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('admin.qa-board.index'));

        $response->assertForbidden();
    }
}
