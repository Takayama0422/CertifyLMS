<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標(EnrollmentGoal) の達成解除ユースケース。
 */
final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        return DB::transaction(function () use ($goal) {
            $goal->update(['achieved_at' => null]);

            return $goal->fresh();
        });
    }
}
