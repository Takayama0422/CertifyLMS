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

    /**
     * レビュー指摘 1 / 2: 支給画面は `can('delete', $thread)` でスレッド削除ボタンの表示可否を判定し、
     * 送信先を `admin.qa-board.destroy` に切り替える。管理者では `delete` が常に false だったため、
     * 削除ボタンが 1 本も描画されずモデレーション機能が使えなかった(投稿者本人でなくても削除できること
     * を画面描画レベルで検証する)。
     */
    public function test_admin_sees_thread_delete_form(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $response = $this->actingAs($admin)->get(route('admin.qa-board.show', $thread));

        $response->assertOk();
        $response->assertSee(route('admin.qa-board.destroy', $thread), false);
    }

    /**
     * レビュー指摘 1 / 2: 回答側も同様に、管理者で回答削除フォームが描画されることを検証する。
     */
    public function test_admin_sees_reply_delete_form(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create([
            'user_id' => User::factory()->student()->create()->id,
            'body' => '回答',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.show', $thread));

        $response->assertOk();
        $response->assertSee(
            route('admin.qa-board.replies.destroy', ['thread' => $thread, 'reply' => $reply]),
            false,
        );
    }
}
