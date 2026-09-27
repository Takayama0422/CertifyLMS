<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /qa-board/{thread}/replies/{reply}` の自己削除を検証する。投稿者本人のみ、件数制約はない。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_delete_own_reply(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '削除される回答']);

        $response = $this->actingAs($author)->delete(route('qa-board.replies.destroy', ['thread' => $thread, 'reply' => $reply]));

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を削除しました。');
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_other_user_cannot_delete(): void
    {
        $author = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '回答']);

        $response = $this->actingAs($other)->delete(route('qa-board.replies.destroy', ['thread' => $thread, 'reply' => $reply]));

        $response->assertForbidden();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_mismatched_thread_and_reply_returns_404(): void
    {
        $author = User::factory()->student()->create();
        $threadA = QaThread::factory()->for(Certification::factory()->published())->create();
        $threadB = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $threadA->replies()->create(['user_id' => $author->id, 'body' => '回答']);

        $response = $this->actingAs($author)->delete(route('qa-board.replies.destroy', ['thread' => $threadB, 'reply' => $reply]));

        $response->assertNotFound();
    }
}
