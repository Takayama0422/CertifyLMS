<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /qa-board` のスレッド新規投稿を検証する。
 * 観点: 正常系(遷移先 + フラッシュ文言 + 常に未解決で作成) / 受講していない資格でも投稿可 /
 * コーチ・管理者は投稿不可 / 未公開資格は拒否 / 入力検証の境界値。
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_thread(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '二分探索木の平均比較回数について',
            'body' => 'オーダーの導出過程がわかりません。',
        ]);

        $thread = QaThread::query()->where('user_id', $student->id)->firstOrFail();
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '質問を投稿しました。');
        $this->assertSame('open', $thread->status->value);
        $this->assertNull($thread->resolved_at);
    }

    public function test_student_can_post_to_certification_not_enrolled_in(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        // 受講登録(Enrollment)を一切作らない

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '未受講の資格への質問',
            'body' => '受講していなくても投稿できるはずです。',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('qa_threads', ['user_id' => $student->id, 'certification_id' => $certification->id]);
    }

    public function test_coach_is_forbidden(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($coach)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => 'コーチからの投稿',
            'body' => '本来は拒否されるはず。',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('qa-board.store'), [
            'certification_id' => Certification::factory()->published()->create()->id,
            'title' => '管理者からの投稿',
            'body' => '本来は拒否されるはず。',
        ]);

        $response->assertForbidden();
    }

    public function test_unpublished_certification_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $draftCert = Certification::factory()->draft()->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), [
                'certification_id' => $draftCert->id,
                'title' => '下書き資格への質問',
                'body' => '公開前の資格には投稿できないはず。',
            ]);

        $response->assertSessionHasErrors(['certification_id']);
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_certification_id_is_required(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), ['title' => 'タイトル', 'body' => '本文']);

        $response->assertSessionHasErrors(['certification_id']);
    }

    public function test_title_at_200_characters_is_accepted(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => str_repeat('あ', 200),
            'body' => '本文',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_title_at_201_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => str_repeat('あ', 201),
                'body' => '本文',
            ]);

        $response->assertSessionHasErrors(['title']);
    }

    public function test_body_at_5000_characters_is_accepted(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => 'タイトル',
            'body' => str_repeat('あ', 5000),
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_body_at_5001_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)
            ->from(route('qa-board.create'))
            ->post(route('qa-board.store'), [
                'certification_id' => $certification->id,
                'title' => 'タイトル',
                'body' => str_repeat('あ', 5001),
            ]);

        $response->assertSessionHasErrors(['body']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->post(route('qa-board.store'), []);

        $response->assertRedirect(route('login'));
    }
}
