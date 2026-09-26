<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AI 相談メッセージの送信(`POST /ai-chat/conversations/{conversation}/messages`)。会話オーナーのみ。
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof AiChatConversation
            && $this->user()?->can('sendMessage', $conversation) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => '質問内容',
        ];
    }
}
