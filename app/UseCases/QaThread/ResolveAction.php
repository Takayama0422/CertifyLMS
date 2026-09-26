<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 投稿者本人によるスレッドの解決マークユースケース。
 */
final class ResolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        return DB::transaction(function () use ($thread) {
            $thread->update([
                'status' => QaThreadStatus::Resolved->value,
                'resolved_at' => now(),
            ]);

            return $thread->fresh();
        });
    }
}
