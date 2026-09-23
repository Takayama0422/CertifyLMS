<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受講登録(Enrollment)配下のコーチメモ。担当コーチ / 管理者が記録する業務記録(受講生には非公開)。
 *
 * 作成者(`user_id` → `author()`)のみが自分のメモを編集 / 削除できる(他コーチのメモは閲覧のみ)。
 * 管理者は全メモを編集 / 削除できる。削除は物理削除。
 */
class EnrollmentNote extends Model
{
    /** @use HasFactory<EnrollmentNoteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'user_id',
        'body',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * メモの作成者(コーチ / 管理者)。
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
