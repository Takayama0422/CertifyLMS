<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Support\Facades\DB;

/**
 * コーチメモ(EnrollmentNote) の更新ユースケース。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated EnrollmentNote/UpdateRequest::rules() で検証済
     */
    public function __invoke(EnrollmentNote $note, array $validated): EnrollmentNote
    {
        return DB::transaction(function () use ($note, $validated) {
            $note->update($validated);

            return $note->fresh();
        });
    }
}
