<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランをアーカイブ（published → archived）するユースケース。アーカイブ後は招待画面 / プラン延長画面の
 * 選択肢から外れる（`Plan::scopePublished` を参照する側の責務）が、受講中ユーザーの参照(`users`)は維持する。
 * 公開中以外の状態からの呼出は PlanInvalidTransitionException（409）。
 */
final class ArchiveAction
{
    /**
     * @throws PlanInvalidTransitionException 公開中以外からの呼出
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        if ($plan->status !== PlanStatus::Published) {
            throw PlanInvalidTransitionException::forArchive();
        }

        return DB::transaction(function () use ($plan, $admin) {
            $plan->update([
                'status' => PlanStatus::Archived->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
