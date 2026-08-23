<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標(EnrollmentGoal) の更新ユースケース。
 */
final class UpdateAction
{
    /**
     * @param array{title: string, description?: ?string, target_date: string} $validated EnrollmentGoal/UpdateRequest::rules() で検証済
     */
    public function __invoke(EnrollmentGoal $goal, array $validated): EnrollmentGoal
    {
        return DB::transaction(function () use ($goal, $validated) {
            $goal->update($validated);

            return $goal->fresh();
        });
    }
}
