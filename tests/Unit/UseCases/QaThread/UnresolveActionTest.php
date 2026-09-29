<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\UseCases\QaThread\UnresolveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UnresolveAction の単体テスト(レビュー指摘 8)。
 * `status` と `resolved_at` は二重管理のため、必ず両方が同時に更新されること
 * (片方だけ更新される経路が無いこと)を検証する。
 */
class UnresolveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unresolve_clears_status_and_resolved_at_together(): void
    {
        $thread = QaThread::factory()->resolved()->create();
        $this->assertSame(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);

        $result = app(UnresolveAction::class)($thread);

        $this->assertSame(QaThreadStatus::Open, $result->status);
        $this->assertNull($result->resolved_at);

        $fresh = $thread->fresh();
        $this->assertSame(QaThreadStatus::Open, $fresh->status);
        $this->assertNull($fresh->resolved_at);
    }

    public function test_unresolve_is_idempotent_on_already_open_thread(): void
    {
        $thread = QaThread::factory()->open()->create();

        $result = app(UnresolveAction::class)($thread);

        $this->assertSame(QaThreadStatus::Open, $result->status);
        $this->assertNull($result->resolved_at);
    }
}
