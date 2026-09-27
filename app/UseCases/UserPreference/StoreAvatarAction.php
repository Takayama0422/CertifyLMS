<?php

declare(strict_types=1);

namespace App\UseCases\UserPreference;

use App\Exceptions\UserPreference\AvatarStorageException;
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
 *
 * - 保存 / DB 更新は `app/UseCases/SectionImage/StoreAction.php` に倣い、失敗時は保存済みの新ファイルを
 *   削除してから例外を投げる(更新失敗で参照されないファイルが溜まり続けるのを防ぐ)。
 * - 差し替え時の旧ファイル削除は `app/UseCases/SectionImage/DestroyAction.php` に倣い `DB::afterCommit()` で
 *   コミット後に回す(トランザクション ROLLBACK 時に実ファイルだけ消えてしまうのを防ぐ)。
 * - 拡張子はクライアント申告値(`getClientOriginalExtension()`)ではなく、検証済みの実データから
 *   `guessExtension()` で判定する。中身が正当な画像でもクライアントが `avatar.phtml` 等の危険な拡張子を
 *   申告した場合に、その拡張子のまま公開領域へ保存されてしまうのを防ぐ
 *   (許可拡張子は `App\Http\Requests\UserPreference\StoreAvatarRequest::rules()` の `mimes:` と同じ集合)。
 */
final class StoreAvatarAction
{
    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    private const FALLBACK_EXTENSION = 'png';

    public function __invoke(User $user, UploadedFile $file): User
    {
        $ulid = (string) Str::ulid();
        $ext = $this->resolveExtension($file);
        $filename = "{$ulid}.{$ext}";
        $previousDiskPath = $this->diskPathFromUrl($user->avatar_url);

        try {
            return DB::transaction(function () use ($user, $file, $filename, $previousDiskPath) {
                Storage::disk('public')->putFileAs('avatars', $file, $filename);

                $user->update(['avatar_url' => '/storage/avatars/'.$filename]);

                if ($previousDiskPath !== null) {
                    DB::afterCommit(fn () => Storage::disk('public')->delete($previousDiskPath));
                }

                return $user->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete("avatars/{$filename}");
            throw new AvatarStorageException($e);
        }
    }

    /**
     * アップロードされたファイルの実データから、検証済みの拡張子を判定する。
     * `StoreAvatarRequest` の `mimes:png,jpg,jpeg,webp` を通過済みである前提のため、
     * `guessExtension()` が許可集合外を返すことは通常無いが、保険として不一致時はフォールバックする。
     */
    private function resolveExtension(UploadedFile $file): string
    {
        $guessed = strtolower((string) ($file->guessExtension() ?? ''));

        return in_array($guessed, self::ALLOWED_EXTENSIONS, true) ? $guessed : self::FALLBACK_EXTENSION;
    }

    private function diskPathFromUrl(?string $url): ?string
    {
        if ($url === null || ! str_starts_with($url, '/storage/')) {
            return null;
        }

        return substr($url, strlen('/storage/'));
    }
}
