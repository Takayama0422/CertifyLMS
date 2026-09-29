<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 追加面談パックの購入選択画面(GET /meeting-quota/checkout)。学習中の受講生本人のみ。
 */
class CheckoutIndexRequest extends FormRequest
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
        return [];
    }
}
