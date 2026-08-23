<?php

declare(strict_types=1);

namespace App\UseCases\UserPreference;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 本人アバター画像アップロードユースケース。
 *
 * `avatars/{ulid}.{ext}` 形式で public disk に保存し、`users.avatar_url` を `/storage/avatars/{ulid}.{ext}`
 * (ブラウザから直接参照できる URL)へ更新する(`app/UseCases/SectionImage/StoreAction.php` と同一の保存規約)。
 * 差し替え時は旧ファイルを新ファイル保存 + DB 更新の成功後に削除し、失敗時に画像が消えたままにならないようにする。
 */
final class StoreAvatarAction
{
    public function __invoke(User $user, UploadedFile $file): User
    {
        $ulid = (string) Str::ulid();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $filename = "{$ulid}.{$ext}";
        $previousDiskPath = $this->diskPathFromUrl($user->avatar_url);

        $updated = DB::transaction(function () use ($user, $file, $filename) {
            Storage::disk('public')->putFileAs('avatars', $file, $filename);

            $user->update(['avatar_url' => '/storage/avatars/'.$filename]);

            return $user->fresh();
        });

        if ($previousDiskPath !== null) {
            Storage::disk('public')->delete($previousDiskPath);
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
