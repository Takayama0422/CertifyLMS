<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /qa-board/{thread}/resolve`・`/unresolve` の解決状態切替を検証する。投稿者本人のみ操作できる。
 */
class ResolveUnresolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_resolve_thread(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->open()->create();

        $response = $this->actingAs($author)->post(route('qa-board.resolve', $thread));

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '解決済にしました。');
        $fresh = $thread->fresh();
        $this->assertSame('resolved', $fresh->status->value);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_author_can_unresolve_thread(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->for($author)->resolved()->create();

        $response = $this->actingAs($author)->post(route('qa-board.unresolve', $thread));

        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '未解決に戻しました。');
        $fresh = $thread->fresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertNull($fresh->resolved_at);
    }

    public function test_other_student_cannot_resolve(): void
    {
        $other = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->open()->create();

        $response = $this->actingAs($other)->post(route('qa-board.resolve', $thread));

        $response->assertForbidden();
        $this->assertSame('open', $thread->fresh()->status->value);
    }

    public function test_coach_cannot_resolve(): void
    {
        $coach = User::factory()->coach()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->open()->create();

        $response = $this->actingAs($coach)->post(route('qa-board.resolve', $thread));

        $response->assertForbidden();
    }
}
