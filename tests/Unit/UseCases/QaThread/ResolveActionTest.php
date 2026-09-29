<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\UseCases\QaThread\ResolveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ResolveAction の単体テスト(レビュー指摘 8)。
 * `status` と `resolved_at` は二重管理のため、必ず両方が同時に更新されること
 * (片方だけ更新される経路が無いこと)を検証する。
 */
class ResolveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_sets_status_and_resolved_at_together(): void
    {
        $thread = QaThread::factory()->open()->create();
        $this->assertSame(QaThreadStatus::Open, $thread->status);
        $this->assertNull($thread->resolved_at);

        $result = app(ResolveAction::class)($thread);

        $this->assertSame(QaThreadStatus::Resolved, $result->status);
        $this->assertNotNull($result->resolved_at);

        $fresh = $thread->fresh();
        $this->assertSame(QaThreadStatus::Resolved, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_resolve_is_idempotent_on_already_resolved_thread(): void
    {
        $thread = QaThread::factory()->resolved()->create(['resolved_at' => now()->subDay()]);

        $result = app(ResolveAction::class)($thread);

        $this->assertSame(QaThreadStatus::Resolved, $result->status);
        $this->assertNotNull($result->resolved_at);
        $this->assertTrue($result->resolved_at->greaterThan(now()->subMinute()));
    }
}
