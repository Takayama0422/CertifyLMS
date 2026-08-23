<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /qa-board/{thread}` の自己削除を検証する。
 * 確定仕様: 回答が 1 件でも付いていたら削除不可。回答 0 件のときのみ削除可(投稿者本人のみ)。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_delete_thread_with_no_replies(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        $response = $this->actingAs($author)->delete(route('qa-board.destroy', $thread));

        $response->assertRedirect(route('qa-board.index'));
        $response->assertSessionHas('success', '質問を削除しました。');
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_author_cannot_delete_thread_with_replies(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();
        $thread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $response = $this->actingAs($author)
            ->from(route('qa-board.show', $thread))
            ->delete(route('qa-board.destroy', $thread));

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('error', '回答が付いている質問は削除できません。');
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_other_student_cannot_delete(): void
    {
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($other)->delete(route('qa-board.destroy', $thread));

        $response->assertForbidden();
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_coach_cannot_delete(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($coach)->delete(route('qa-board.destroy', $thread));

        $response->assertForbidden();
    }
}
