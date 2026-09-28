<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 決済完了画面(GET /meeting-quota/success)。学習中の受講生本人のみ。
 * `session_id` は決済サービスが success_url に付与する値で、任意(未付与でも完了画面自体は表示する)。
 */
class SuccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase-meeting-quota') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['nullable', 'string'],
        ];
    }
}
