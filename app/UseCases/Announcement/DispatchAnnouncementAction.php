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
 * 2. 配信対象タイプ(全受講生 / 資格指定 / ユーザー指定)に応じて対象受講生を解決し、配信件数を記録する
 * 3. コミット後(`DB::afterCommit()`)に対象受講生それぞれへ `notify()` を呼ぶ
 *    (S-B-04 の chat / qa 回答通知と同じ扱い: トランザクション内に置くと、送信失敗時に既に届いた
 *    通知を取り消せないまま配信履歴だけが巻き戻り、再操作で二重送信を招くため)。
 *    `dispatched_count` / `dispatched_at` は「配信対象として確定した数」であり、対象受講生数を指す
 *    (T-A-05 で `AdminAnnouncementNotification` がキュー非同期化された後も、この時点で確定済みの
 *    件数を記録するだけなので整合は崩れない。実際の配信(database 書き込み + mail 送信)は
 *    `notify()` が積んだバックグラウンドジョブが順次処理する)
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

            $announcement->update(['dispatched_count' => $recipients->count()]);

            DB::afterCommit(function () use ($announcement, $recipients): void {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new AdminAnnouncementNotification($announcement));
                }
            });

            return $announcement->fresh();
        });
    }

    /**
     * 配信対象タイプに応じた対象受講生を解決する。
     *
     * `AnnouncementSeeder` が実メール送信を避けつつ本番と同じ対象解決規則で開発データを作るために、
     * ここだけを再利用できるよう public にしている(通知配信そのものは行わない)。
     *
     * @return Collection<int, User>
     */
    public function resolveRecipients(Announcement $announcement): Collection
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
