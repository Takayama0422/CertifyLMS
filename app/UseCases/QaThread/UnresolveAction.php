<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Support\Facades\DB;

/**
 * 投稿者本人によるスレッドの未解決差し戻しユースケース。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        return DB::transaction(function () use ($thread) {
            $thread->update([
                'status' => QaThreadStatus::Open->value,
                'resolved_at' => null,
            ]);

            return $thread->fresh();
        });
    }
}
