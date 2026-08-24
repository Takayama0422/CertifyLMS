<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 追加面談パックの購入実行(POST /meeting-quota/checkout)。学習中の受講生本人のみ。
 *
 * `meeting_pack_id` の存在検証のみここで行う。「公開中の面談パックか」は Model 単位の判定のため
 * Controller 側で `MeetingPackPolicy::purchase` により authorize する(公開中でないパックを
 * URL 直指定(フォームの meeting_pack_id を書き換え)しても購入できないことの担保)。
 */
class CheckoutStoreRequest extends FormRequest
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
            'meeting_pack_id' => ['required', 'string', 'exists:meeting_packs,id'],
        ];
    }
}
