<?php

declare(strict_types=1);

namespace App\Http\Requests\UserPreference;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人プロフィール更新リクエスト(`PATCH /settings/profile`)。
 *
 * 対象は常にログイン中の本人(`$this->user()`)で、他ユーザーを指す route パラメータを一切持たないため、
 * Policy を介さず `authorize()` に「認証済みであること」のみを書く(このリクエストが更新できるのは常に自分自身)。
 * email はここに rules() を持たない = どんな入力を送っても validated() に含まれず更新されない(表示専用)。
 * meeting_url はコーチのみ rules() に追加する。受講生・管理者が送っても検証されず無視される。
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bio' => $this->filled('bio') ? $this->input('bio') : null,
            'meeting_url' => $this->filled('meeting_url') ? $this->input('meeting_url') : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->user()?->role === UserRole::Coach) {
            $rules['meeting_url'] = ['nullable', 'string', 'url', 'max:500'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'bio' => '自己紹介',
            'meeting_url' => '固定面談 URL',
        ];
    }
}
