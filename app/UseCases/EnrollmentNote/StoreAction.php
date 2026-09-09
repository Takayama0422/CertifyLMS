<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * コーチメモ(EnrollmentNote) の新規作成ユースケース。作成者は常にログイン中のコーチ / 管理者。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated EnrollmentNote/StoreRequest::rules() で検証済
     */
    public function __invoke(Enrollment $enrollment, User $author, array $validated): EnrollmentNote
    {
        return DB::transaction(fn () => $enrollment->notes()->create([
            ...$validated,
            'user_id' => $author->id,
        ]));
    }
}
