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
 *
 * users.plan_id / user_plan_logs.plan_id は restrictOnDelete の外部キーのため、
 * 退会済み（論理削除済み）ユーザーや過去の割当・延長履歴が残っている場合も物理削除できない。
 * ここで弾かず物理削除を試みると、409 ではなく外部キー制約違反で 500 になってしまう。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException 下書き以外、または受講者(論理削除済み含む)・履歴が紐づいている場合
     */
    public function __invoke(Plan $plan): void
    {
        if (
            $plan->status !== PlanStatus::Draft
            || $plan->users()->withTrashed()->exists()
            || $plan->userPlanLogs()->exists()
        ) {
            throw new PlanNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
