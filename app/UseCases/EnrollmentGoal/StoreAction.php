<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

/**
 * 個人学習目標(EnrollmentGoal) の新規作成ユースケース。
 */
final class StoreAction
{
    /**
     * @param array{title: string, description?: ?string, target_date: string} $validated EnrollmentGoal/StoreRequest::rules() で検証済
     */
    public function __invoke(Enrollment $enrollment, array $validated): EnrollmentGoal
    {
        return DB::transaction(fn () => $enrollment->goals()->create($validated));
    }
}
