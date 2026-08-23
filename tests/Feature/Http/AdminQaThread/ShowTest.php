<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AdminQaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /admin/qa-board/{thread}` の管理者モデレーション詳細を検証する。公開停止中の資格も閲覧できる。
 */
class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_thread_on_unpublished_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $response = $this->actingAs($admin)->get(route('admin.qa-board.show', $thread));

        $response->assertOk();
        $response->assertViewIs('qa-thread.show');
    }

    public function test_student_is_forbidden(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)->get(route('admin.qa-board.show', $thread));

        $response->assertForbidden();
    }
}
