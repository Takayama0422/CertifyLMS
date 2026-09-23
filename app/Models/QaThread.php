<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use Database\Factories\QaThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 質問掲示板のスレッド(質問)。投稿者(受講生)・対象資格・タイトル・本文・解決状態を持つ。
 *
 * 関連: User(投稿者) / Certification(対象資格) / QaReply(配下の回答、複数件)。
 * `status` は `App\Enums\QaThreadStatus` にキャストされる実カラム(一覧の絞り込みで直接 WHERE に使うため)。
 */
class QaThread extends Model
{
    /** @use HasFactory<QaThreadFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'certification_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * 配下の回答。投稿順(古い順)に並べる。
     *
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class)->orderBy('created_at');
    }

    /**
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', QaThreadStatus::Resolved->value);
    }

    /**
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('status', QaThreadStatus::Open->value);
    }
}
