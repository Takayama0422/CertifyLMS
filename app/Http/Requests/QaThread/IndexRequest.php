<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問掲示板スレッド一覧アクセスの入力検証。公開画面(`GET /qa-board`、受講生 / コーチ)と
 * 管理者モデレーション画面(`GET /admin/qa-board`)の両方で共用する。
 * 資格・解決状態・キーワードでの絞り込みを受け付ける(可視範囲そのものの絞り込みは Action 側)。
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->user()?->role;

        return $role === UserRole::Student || $role === UserRole::Coach || $role === UserRole::Admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->filled('status') ? $this->input('status') : null,
            'certification_id' => $this->filled('certification_id') ? $this->input('certification_id') : null,
            'keyword' => $this->filled('keyword') ? $this->input('keyword') : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:resolved,unresolved'],
            'certification_id' => ['nullable', 'ulid', 'exists:certifications,id'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{status: ?string, certification_id: ?string, keyword: ?string}
     */
    public function filters(): array
    {
        return [
            'status' => $this->validated('status'),
            'certification_id' => $this->validated('certification_id'),
            'keyword' => $this->validated('keyword'),
        ];
    }
}
