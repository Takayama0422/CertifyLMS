<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiChatConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI 相談(Gemini AI チャットボット, S-A-02)の会話 1 件。
 *
 * オーナーは常に受講生本人(user)。閲覧中の教材(section)・受講中の資格(enrollment 経由)を
 * 文脈として保持し、Gemini への入力に自動で添えるために使う(いずれも任意)。
 *
 * 関連: User(owner) / Enrollment(資格文脈, 任意) / Section(教材文脈, 任意) / AiChatMessage(発言)
 */
class AiChatConversation extends Model
{
    /** @use HasFactory<AiChatConversationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'enrollment_id',
        'section_id',
        'title',
        'title_manually_set',
        'last_message_at',
    ];

    protected $casts = [
        'title_manually_set' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return HasMany<AiChatMessage, $this>
     */
    public function messages(): HasMany
    {
        // 1 リクエスト内で質問 → 回答を連続保存するため created_at が同一秒になるのが常態。
        // id(ULID)はミリ秒精度の生成時刻 + 単調増加な乱数部を持つため、created_at が同値でも
        // 生成順どおりに並ぶ安全なタイブレーカーとして使う。
        return $this->hasMany(AiChatMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
