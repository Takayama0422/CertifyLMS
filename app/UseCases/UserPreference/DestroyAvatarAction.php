<?php

declare(strict_types=1);

namespace App\UseCases\UserPreference;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 本人アバター画像削除ユースケース。`avatar_url` を NULL に戻し、未設定表示(イニシャル表示)に戻す。
 */
final class DestroyAvatarAction
{
    public function __invoke(User $user): User
    {
        $diskPath = $this->diskPathFromUrl($user->avatar_url);

        $updated = DB::transaction(function () use ($user) {
            $user->update(['avatar_url' => null]);

            return $user->fresh();
        });

        if ($diskPath !== null) {
            Storage::disk('public')->delete($diskPath);
        }

        return $updated;
    }

    private function diskPathFromUrl(?string $url): ?string
    {
        if ($url === null || ! str_starts_with($url, '/storage/')) {
            return null;
        }

        return substr($url, strlen('/storage/'));
    }
}
