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
 * users.plan_id は restrictOnDelete の外部キーのため、退会済み（論理削除済み）ユーザーが
 * 紐づいている場合は物理削除できない。ここで弾かず物理削除を試みると、
 * 409 ではなく外部キー制約違反で 500 になってしまう。
 *
 * user_plan_logs.plan_id は nullOnDelete のため、過去の割当・延長履歴が残っていても
 * 履歴行自体は保持したまま plan_id だけが NULL になり、物理削除を妨げない。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException 下書き以外、または受講者(論理削除済み含む)が紐づいている場合
     */
    public function __invoke(Plan $plan): void
    {
        if (
            $plan->status !== PlanStatus::Draft
            || $plan->users()->withTrashed()->exists()
        ) {
            throw new PlanNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
