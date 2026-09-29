<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * お知らせ新規配信リクエスト(`POST /admin/announcements`、管理者のみ)。
 * 配信対象タイプによって、資格 / ユーザーいずれかの補助入力が必須になる(要件シート S3)。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                Rule::requiredIf(fn () => $this->input('target_type') === AnnouncementTargetType::Certification->value),
                'nullable',
                'string',
                Rule::exists('certifications', 'id'),
            ],
            'target_user_id' => [
                Rule::requiredIf(fn () => $this->input('target_type') === AnnouncementTargetType::User->value),
                'nullable',
                'string',
                Rule::exists('users', 'id')
                    ->where(fn ($query) => $query
                        ->where('role', UserRole::Student->value)
                        ->where('status', UserStatus::InProgress->value)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => '配信対象',
            'target_certification_id' => '対象資格',
            'target_user_id' => '対象受講生',
        ];
    }
}
