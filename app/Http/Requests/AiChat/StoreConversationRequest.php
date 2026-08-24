<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AI 相談の会話新規作成(`POST /ai-chat/conversations`)。
 *
 * `message`(初回メッセージ)は任意 — フル画面の新規会話モーダルは入力必須にしていない
 * (「後から送信可」ヒント文言どおり)。`section_id` はウィジェットが教材文脈から自動で付与する。
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'max:20'],
            'section_id' => ['nullable', 'string', 'exists:sections,id'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'section_id' => '教材',
            'message' => '最初の質問',
        ];
    }
}
