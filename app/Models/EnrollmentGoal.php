<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentGoalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録(Enrollment)配下の個人学習目標。受講生本人のみが CRUD する自由入力の 1 行フラット構造。
 *
 * 達成状況は `achieved_at` の有無のみで表現する(達成 → 解除の履歴は持たない)。
 * 削除は物理削除で、親 Enrollment が削除された場合は `Enrollment\DestroyAction` 側で連動削除する。
 */
class EnrollmentGoal extends Model
{
    /** @use HasFactory<EnrollmentGoalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
