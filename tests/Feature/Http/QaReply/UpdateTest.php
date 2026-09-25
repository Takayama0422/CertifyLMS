<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /qa-board/{thread}/replies/{reply}/edit`・`PATCH .../{reply}` を検証する。投稿者本人のみ編集可。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_edit_form(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '元の回答']);

        $response = $this->actingAs($author)->get(route('qa-board.replies.edit', ['thread' => $thread, 'reply' => $reply]));

        $response->assertOk();
    }

    public function test_author_can_update_reply(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '元の回答']);

        $response = $this->actingAs($author)->patch(
            route('qa-board.replies.update', ['thread' => $thread, 'reply' => $reply]),
            ['body' => '更新後の回答']
        );

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を更新しました。');
        $this->assertSame('更新後の回答', $reply->fresh()->body);
    }

    public function test_other_author_cannot_update(): void
    {
        $author = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '元の回答']);

        $response = $this->actingAs($other)->patch(
            route('qa-board.replies.update', ['thread' => $thread, 'reply' => $reply]),
            ['body' => '不正な更新']
        );

        $response->assertForbidden();
    }

    public function test_mismatched_thread_and_reply_returns_404(): void
    {
        $author = User::factory()->student()->create();
        $threadA = QaThread::factory()->for(Certification::factory()->published())->create();
        $threadB = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $threadA->replies()->create(['user_id' => $author->id, 'body' => '元の回答']);

        $response = $this->actingAs($author)->patch(
            route('qa-board.replies.update', ['thread' => $threadB, 'reply' => $reply]),
            ['body' => '不正な更新']
        );

        $response->assertNotFound();
    }

    public function test_body_over_5000_characters_is_rejected(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $author->id, 'body' => '元の回答']);

        $response = $this->actingAs($author)
            ->from(route('qa-board.replies.edit', ['thread' => $thread, 'reply' => $reply]))
            ->patch(
                route('qa-board.replies.update', ['thread' => $thread, 'reply' => $reply]),
                ['body' => str_repeat('あ', 5001)]
            );

        $response->assertSessionHasErrors(['body']);
    }
}
