<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Database\Factories\AiChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 相談(Gemini AI チャットボット, S-A-02)の発言 1 件。
 *
 * user_id は常に会話オーナー(受講生)の非正規化カラム(assistant 発言でも同じ値。1 日あたりの
 * 送信回数上限をこのカラム + role = user だけで集計するための設計)。
 *
 * model / input_tokens / output_tokens / response_time_ms は運用観測用の内部記録。
 */
class AiChatMessage extends Model
{
    /** @use HasFactory<AiChatMessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'ai_chat_conversation_id',
        'user_id',
        'role',
        'status',
        'content',
        'error_detail',
        'model',
        'input_tokens',
        'output_tokens',
        'response_time_ms',
    ];

    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'response_time_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'ai_chat_conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
