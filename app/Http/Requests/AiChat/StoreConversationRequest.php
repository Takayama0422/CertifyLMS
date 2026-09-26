<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AI 相談の会話新規作成(`POST /ai-chat/conversations`)。
 *
 * `message`(初回メッセージ)は任意 — フル画面の新規会話モーダルは入力必須にしていない
 * (「後から送信可」ヒント文言どおり)。`section_id` はウィジェットが教材文脈から自動で付与する。
 *
 * `section_id` は単なる存在チェックに留めず、「受講中の資格の、公開されている教材」のみを受け付ける。
 * 受講していない資格の教材や下書きの教材の ID を送っても会話を作れてしまうと、その教材名が会話の
 * 見出し・画面・AI への指示にそのまま載ってしまうため。
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
            'section_id' => ['nullable', 'string', $this->validSectionRule()],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function validSectionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            $section = Section::query()
                ->published()
                ->with('chapter.part')
                ->find($value);

            if ($section === null) {
                $fail('指定された教材は利用できません。');

                return;
            }

            $certificationId = $section->chapter?->part?->certification_id;

            $enrolled = $certificationId !== null
                && $this->user()->enrollments()
                    ->where('certification_id', $certificationId)
                    ->where('status', EnrollmentStatus::Learning->value)
                    ->exists();

            if (! $enrolled) {
                $fail('指定された教材は利用できません。');
            }
        };
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
