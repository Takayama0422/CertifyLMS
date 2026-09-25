<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AdminQaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /admin/qa-board/{thread}` の管理者モデレーション削除を検証する。
 * 投稿者本人削除と異なり、回答が付いていても削除できる(内容編集・解決マーク代行は提供しない)。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_thread_with_replies(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $thread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.destroy', $thread));

        $response->assertRedirect(route('admin.qa-board.index'));
        $response->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_admin_can_delete_thread_on_unpublished_certification(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.destroy', $thread));

        $response->assertRedirect(route('admin.qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_student_is_forbidden(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)->delete(route('admin.qa-board.destroy', $thread));

        $response->assertForbidden();
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_author_using_admin_route_is_forbidden(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        // /admin プレフィックスは role:admin ミドルウェアで守られているため、投稿者本人でも 403
        $response = $this->actingAs($author)->delete(route('admin.qa-board.destroy', $thread));

        $response->assertForbidden();
    }
}
