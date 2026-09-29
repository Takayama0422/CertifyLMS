<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * 管理者お知らせ配信(S-B-08)のデモデータシーダー。
 *
 * **設計思想**: 配信対象の 3 種類(全受講生 / 資格指定 / ユーザー指定)それぞれのお知らせを投入する
 * (要件シート S9)。対象受講生の解決規則は `DispatchAnnouncementAction::resolveRecipients()` を
 * そのまま再利用し、本番の配信結果と一致させる。
 *
 * 一方で実 Action(`__invoke`)は呼ばない。実 Action は `$recipient->notify()` を経由して
 * 同期メールを実送信するため、`migrate:fresh --seed` のたびに全対象受講生へメールが飛んでしまう
 * (`NotificationSeeder` が同じ理由で `notify()` を経由せず `DatabaseNotification` へ直接 INSERT
 * しているのと同じ方針に揃える)。
 *
 * 依存順序: UserSeeder → CertificationSeeder → EnrollmentSeeder の後に走る前提。
 */
final class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@certify-lms.test')->first();

        if ($admin === null) {
            $this->command?->warn('AnnouncementSeeder: 固定管理者が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $this->dispatchWithoutMail($admin, [
            'title' => 'メンテナンスのお知らせ',
            'body' => "日頃より Certify LMS をご利用いただきありがとうございます。\n\n下記日程でシステムメンテナンスを実施いたします。メンテナンス中はログインを含む全機能がご利用いただけません。\n\nご不便をおかけしますが、よろしくお願いいたします。",
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
        ]);

        $certification = Certification::query()
            ->whereHas('enrollments', fn ($q) => $q->whereHas(
                'user',
                fn ($uq) => $uq->where('role', UserRole::Student->value)->where('status', UserStatus::InProgress->value),
            ))
            ->first();

        if ($certification !== null) {
            $this->dispatchWithoutMail($admin, [
                'title' => '教材の一部更新について',
                'body' => '対象資格の教材内容を一部更新しました。最新の出題傾向を反映していますので、既に学習済みの範囲も含めてご確認をお願いいたします。',
                'target_type' => AnnouncementTargetType::Certification->value,
                'target_certification_id' => $certification->id,
                'target_user_id' => null,
            ]);
        }

        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        if ($student !== null) {
            $this->dispatchWithoutMail($admin, [
                'title' => '学習状況についてのご連絡',
                'body' => 'いつも学習お疲れ様です。運営担当より個別にご連絡です。学習の進め方について気になる点がございましたら、いつでもコーチまでご相談ください。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_certification_id' => null,
                'target_user_id' => $student->id,
            ]);
        }
    }

    /**
     * `DispatchAnnouncementAction` と同じ対象解決規則で配信履歴 + 通知を作るが、`notify()` を
     * 経由しないため実メールは送信しない(`data` は実際の通知クラスの `toArray()` から作るため、
     * 一覧・詳細ページが読むキー構成は実装本体と一致する)。
     *
     * @param array{
     *     title: string,
     *     body: string,
     *     target_type: string,
     *     target_certification_id: ?string,
     *     target_user_id: ?string,
     * } $validated
     */
    private function dispatchWithoutMail(User $admin, array $validated): void
    {
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

        $recipients = app(DispatchAnnouncementAction::class)->resolveRecipients($announcement);

        foreach ($recipients as $recipient) {
            // AdminAnnouncementNotification::url() は自身の DatabaseNotification ID に依存するため、
            // notify() が内部でやるのと同じ順序(id を採番 → toArray())を手動で再現する。
            $notification = new AdminAnnouncementNotification($announcement);
            $notification->id = (string) Str::uuid();

            DatabaseNotification::query()->create([
                'id' => $notification->id,
                'type' => $notification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $recipient->id,
                'data' => $notification->toArray($recipient),
                'read_at' => null,
            ]);
        }

        $announcement->update(['dispatched_count' => $recipients->count()]);
    }
}
