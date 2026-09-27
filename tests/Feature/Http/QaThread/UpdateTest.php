<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /qa-board/{thread}/edit`・`PATCH /qa-board/{thread}` を検証する。
 * 観点: 投稿者本人のみ編集可 / 資格は変更できない / 他の受講生・コーチ・管理者は不可 / 境界値。
 */
class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_edit_form(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        $response = $this->actingAs($author)->get(route('qa-board.edit', $thread));

        $response->assertOk();
    }

    public function test_other_student_cannot_view_edit_form(): void
    {
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($other)->get(route('qa-board.edit', $thread));

        $response->assertForbidden();
    }

    public function test_author_can_update_title_and_body(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => '更新後のタイトル',
            'body' => '更新後の本文',
        ]);

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '質問を更新しました。');
        $this->assertSame('更新後のタイトル', $thread->fresh()->title);
    }

    public function test_certification_cannot_be_changed(): void
    {
        $author = User::factory()->student()->create();
        $originalCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($originalCert)->for($author)->create();

        $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => 'タイトル',
            'body' => '本文',
            'certification_id' => $otherCert->id,
        ]);

        $this->assertSame($originalCert->id, $thread->fresh()->certification_id);
    }

    public function test_other_student_cannot_update(): void
    {
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($other)->patch(route('qa-board.update', $thread), [
            'title' => '不正な更新',
            'body' => '不正な更新',
        ]);

        $response->assertForbidden();
    }

    public function test_coach_cannot_update(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($coach)->patch(route('qa-board.update', $thread), [
            'title' => '不正な更新',
            'body' => '不正な更新',
        ]);

        $response->assertForbidden();
    }

    public function test_title_over_200_characters_is_rejected(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->create();

        $response = $this->actingAs($author)
            ->from(route('qa-board.edit', $thread))
            ->patch(route('qa-board.update', $thread), [
                'title' => str_repeat('あ', 201),
                'body' => '本文',
            ]);

        $response->assertSessionHasErrors(['title']);
    }
}
