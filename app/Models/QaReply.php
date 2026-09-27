<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QaReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 質問掲示板の回答。1 件の QaThread に紐づき、投稿者(受講生 / コーチ)を持つ。ネスト回答は持たない(フラット構造)。
 */
class QaReply extends Model
{
    /** @use HasFactory<QaReplyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'qa_thread_id',
        'user_id',
        'body',
    ];

    /**
     * @return BelongsTo<QaThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(QaThread::class, 'qa_thread_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
