<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /qa-board/{thread}/replies` の回答投稿を検証する。
 * 観点: 受講生 / 担当コーチは投稿可 / 担当外コーチ・管理者は不可 / 境界値 / HTML 遷移先とフラッシュ文言。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_post_reply(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '私はこう理解しています。',
        ]);

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を投稿しました。');
        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '私はこう理解しています。',
        ]);
    }

    public function test_assigned_coach_can_post_reply(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $cert = Certification::factory()->published()->create();
        $this->assignCoach($coach, $cert, $admin);
        $thread = QaThread::factory()->for($cert)->create();

        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => 'コーチからの回答です。',
        ]);

        $response->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseHas('qa_replies', ['qa_thread_id' => $thread->id, 'user_id' => $coach->id]);
    }

    public function test_unassigned_coach_cannot_post_reply(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '担当外からの回答',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_admin_cannot_post_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($admin)->post(route('qa-board.replies.store', $thread), [
            'body' => '管理者からの回答',
        ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_reply_on_unpublished_certification_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '回答',
        ]);

        $response->assertForbidden();
    }

    public function test_body_is_required(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.show', $thread))
            ->post(route('qa-board.replies.store', $thread), ['body' => '']);

        $response->assertSessionHasErrors(['body']);
    }

    public function test_body_at_5000_characters_is_accepted(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => str_repeat('あ', 5000),
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_body_at_5001_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.show', $thread))
            ->post(route('qa-board.replies.store', $thread), ['body' => str_repeat('あ', 5001)]);

        $response->assertSessionHasErrors(['body']);
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
