<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AdminQaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /admin/qa-board/{thread}/replies/{reply}` の管理者モデレーションによる回答削除を検証する。
 */
class DestroyReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_any_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '不適切な回答']);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.replies.destroy', ['thread' => $thread, 'reply' => $reply]));

        $response->assertRedirect(route('admin.qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を削除しました。');
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_student_is_forbidden(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);

        $response = $this->actingAs($student)->delete(route('admin.qa-board.replies.destroy', ['thread' => $thread, 'reply' => $reply]));

        $response->assertForbidden();
    }

    public function test_mismatched_thread_and_reply_returns_404(): void
    {
        $admin = User::factory()->admin()->create();
        $threadA = QaThread::factory()->for(Certification::factory()->published())->create();
        $threadB = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $threadA->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.replies.destroy', ['thread' => $threadB, 'reply' => $reply]));

        $response->assertNotFound();
    }
}
