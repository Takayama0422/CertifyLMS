<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * プランを物理削除するユースケース。削除できるのは「下書きかつ受講者が紐づいていない」場合のみ
 * （招待・受講中ユーザーの参照整合性を守るため）。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException 下書き以外、または受講者が紐づいている場合
     */
    public function __invoke(Plan $plan): void
    {
        if ($plan->status !== PlanStatus::Draft || $plan->users()->exists()) {
            throw new PlanNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
