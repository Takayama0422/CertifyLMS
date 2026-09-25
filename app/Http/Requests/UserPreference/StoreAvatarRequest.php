<?php

declare(strict_types=1);

namespace App\Http\Requests\UserPreference;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 本人アバター画像アップロードリクエスト(`POST /settings/avatar`)。
 * PNG / JPG / JPEG / WebP のみ・2MB(2048KB)以内(`app/Http/Requests/SectionImage/StoreRequest.php` と同一規約)。
 */
class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'アイコン画像',
        ];
    }
}
