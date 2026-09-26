<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 質問スレッド新規投稿リクエスト(`POST /qa-board`)。受講生のみ。
 *
 * 対象資格が route パラメータではなく入力値(`certification_id`)のため、`QuestionCategory/StoreRequest.php` の
 * ような「route から解決した Model で Policy を引く」形にできない。`authorize()` は `QaThreadPolicy::create`
 * (ロールのみを判定)に委譲し、「資格は公開中であること」は `rules()` の `exists`(status=published の WHERE 付き)
 * という入力検証の責務として扱う。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QaThread::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => [
                'required',
                'string',
                Rule::exists('certifications', 'id')
                    ->where(fn ($query) => $query->where('status', CertificationStatus::Published->value)),
            ],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'certification_id' => '資格',
            'title' => 'タイトル',
            'body' => '本文',
        ];
    }
}
