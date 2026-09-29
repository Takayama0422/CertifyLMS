<?php

declare(strict_types=1);

namespace App\UseCases\UserPreference;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 本人プロフィール(氏名 / 自己紹介 / 固定面談 URL)更新ユースケース。
 * email は決して受け取らない(UpdateProfileRequest::rules() に存在しないため validated() にも含まれない)。
 *
 * @param array{name: string, bio?: ?string, meeting_url?: ?string} $validated UpdateProfileRequest::rules() で検証済
 */
final class UpdateProfileAction
{
    public function __invoke(User $user, array $validated): User
    {
        return DB::transaction(function () use ($user, $validated) {
            $user->update($validated);

            return $user->fresh();
        });
    }
}
