<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * お知らせ配信ユースケース。配信は即時 1 回のみで不可逆(再配信 / 編集 / 取消の経路を設けない)。
 *
 * 1. `announcements` へ配信履歴を 1 件作成する
 * 2. 配信対象タイプ(全受講生 / 資格指定 / ユーザー指定)に応じて対象受講生を解決する
 *    (受講生のみ・退会済 / 招待中 / 修了済は対象外。要件シート S12-06)
 * 3. 対象受講生それぞれへアプリ内通知 + メールを同期送信し、配信件数を記録する
 *
 * @param array{
 *     title: string,
 *     body: string,
 *     target_type: string,
 *     target_certification_id: ?string,
 *     target_user_id: ?string,
 * } $validated Announcement/StoreRequest::rules() で検証済
 */
final class DispatchAnnouncementAction
{
    public function __invoke(User $admin, array $validated): Announcement
    {
        return DB::transaction(function () use ($admin, $validated) {
            $announcement = Announcement::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_type' => $validated['target_type'],
                'target_certification_id' => $validated['target_certification_id'] ?? null,
                'target_user_id' => $validated['target_user_id'] ?? null,
                'created_by_user_id' => $admin->id,
                'dispatched_count' => 0,
                'dispatched_at' => now(),
            ]);

            $recipients = $this->resolveRecipients($announcement);

            foreach ($recipients as $recipient) {
                $recipient->notify(new AdminAnnouncementNotification($announcement));
            }

            $announcement->update(['dispatched_count' => $recipients->count()]);

            return $announcement->fresh();
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(Announcement $announcement): Collection
    {
        $query = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value);

        match ($announcement->target_type) {
            AnnouncementTargetType::AllStudents => null,
            AnnouncementTargetType::Certification => $query->whereHas(
                'enrollments',
                fn (Builder $q) => $q->where('certification_id', $announcement->target_certification_id),
            ),
            AnnouncementTargetType::User => $query->where('id', $announcement->target_user_id),
        };

        return $query->get();
    }
}
