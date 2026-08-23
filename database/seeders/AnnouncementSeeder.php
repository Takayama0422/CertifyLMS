<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Database\Seeder;

/**
 * 管理者お知らせ配信(S-B-08)のデモデータシーダー。
 *
 * **設計思想**: 配信対象の 3 種類(全受講生 / 資格指定 / ユーザー指定)それぞれのお知らせを、
 * 実際の配信ユースケース(`DispatchAnnouncementAction`)を通して投入する(要件シート S9)。
 * 実際の Action を使うことで、配信履歴の `dispatched_count` と紐づく受講生通知が
 * 本番の配信結果と完全に一致した状態になる。
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

        $action = app(DispatchAnnouncementAction::class);

        $action($admin, [
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
            $action($admin, [
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
            $action($admin, [
                'title' => '学習状況についてのご連絡',
                'body' => 'いつも学習お疲れ様です。運営担当より個別にご連絡です。学習の進め方について気になる点がございましたら、いつでもコーチまでご相談ください。',
                'target_type' => AnnouncementTargetType::User->value,
                'target_certification_id' => null,
                'target_user_id' => $student->id,
            ]);
        }
    }
}
