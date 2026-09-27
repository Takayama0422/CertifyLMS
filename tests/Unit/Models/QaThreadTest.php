<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QaThread モデルの Cast・リレーションを検証する Unit テスト(レビュー指摘 8)。
 * `status` / `resolved_at` は Action 側で二重管理されるため Cast の型を固定しておくことが重要、
 * `replies()` は投稿順(古い順)であることを固定する。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_cast_to_enum(): void
    {
        $thread = QaThread::factory()->open()->create();

        $this->assertInstanceOf(QaThreadStatus::class, $thread->status);
        $this->assertSame(QaThreadStatus::Open, $thread->status);
    }

    public function test_resolved_at_is_cast_to_datetime(): void
    {
        $thread = QaThread::factory()->resolved()->create();

        $this->assertInstanceOf(Carbon::class, $thread->resolved_at);
    }

    public function test_replies_relation_orders_by_created_at_ascending(): void
    {
        $thread = QaThread::factory()->create();
        $oldest = $thread->replies()->create([
            'user_id' => User::factory()->student()->create()->id,
            'body' => '最初の回答',
            'created_at' => now()->subMinutes(10),
        ]);
        $newest = $thread->replies()->create([
            'user_id' => User::factory()->student()->create()->id,
            'body' => '後の回答',
            'created_at' => now(),
        ]);

        $ordered = $thread->replies()->get();

        $this->assertTrue($ordered->first()->is($oldest));
        $this->assertTrue($ordered->last()->is($newest));
    }

    public function test_user_relation_returns_author(): void
    {
        $author = User::factory()->student()->create();
        $thread = QaThread::factory()->for($author)->create();

        $this->assertTrue($thread->user->is($author));
    }

    public function test_certification_relation_returns_target_certification(): void
    {
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();

        $this->assertTrue($thread->certification->is($certification));
    }

    public function test_resolved_scope_returns_only_resolved_threads(): void
    {
        $resolved = QaThread::factory()->resolved()->create();
        QaThread::factory()->open()->create();

        $ids = QaThread::resolved()->pluck('id');

        $this->assertTrue($ids->contains($resolved->id));
        $this->assertCount(1, $ids);
    }

    public function test_unresolved_scope_returns_only_open_threads(): void
    {
        $open = QaThread::factory()->open()->create();
        QaThread::factory()->resolved()->create();

        $ids = QaThread::unresolved()->pluck('id');

        $this->assertTrue($ids->contains($open->id));
        $this->assertCount(1, $ids);
    }
}
