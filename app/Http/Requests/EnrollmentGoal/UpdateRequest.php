<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * 個人学習目標(EnrollmentGoal) の更新リクエスト。受講生本人のみ許可。
 *
 * 「過去日は不可」は新規入力値の決定であり、既に期日を過ぎたレコードの編集を塞ぐものではない。
 * そのため target_date を変更しない更新(タイトルのみ修正等)は過去日のままでも許容し、
 * target_date を新しく指定し直す場合のみ新規作成時と同じ「当日以降」を課す。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $goal = $this->route('goal');

        return $goal instanceof EnrollmentGoal
            && ($this->user()?->can('update', $goal) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_date' => [
                'required',
                'date',
                Rule::when(fn () => $this->isTargetDateChanged(), ['after_or_equal:today']),
            ],
        ];
    }

    /**
     * 送信された target_date が、更新対象の現在の target_date と異なるか。
     * 比較できない(route model binding が無い / 未送信 / 不正値)場合は安全側(変更あり扱い)に倒す。
     */
    private function isTargetDateChanged(): bool
    {
        $goal = $this->route('goal');
        $input = $this->input('target_date');

        if (! $goal instanceof EnrollmentGoal || ! is_string($input) || $input === '') {
            return true;
        }

        try {
            return ! Carbon::parse($input)->isSameDay($goal->target_date);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => '目標',
            'description' => '詳細',
            'target_date' => '目標期日',
        ];
    }
}
