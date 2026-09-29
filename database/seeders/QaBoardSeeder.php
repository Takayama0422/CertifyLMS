<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * 質問掲示板のデモデータシーダー。
 *
 * **設計思想**:
 *
 * 1. **資格ごとの状態網羅**: 公開済資格ごとに未解決 2 件 + 解決済 2 件を必ず作り、一覧の解決状態フィルタ・
 *    バッジ(解決済 / 未回答 / 未解決)が全パターン見える状態にする。
 * 2. **回答数のばらつき**: 各スレッドの回答数を 0 件 / 1〜2 件 / 4〜6 件でばらけさせ、削除ガード
 *    (回答 0 件のみ自己削除可)・並び順・N+1 非回帰の実機確認をしやすくする。
 * 3. **作成日時のばらつき**: 過去 30 日に分散させ、新着順ページネーションの動作確認をしやすくする。
 * 4. **固定受講生の投稿**: `student@certify-lms.test` を投稿者にしたスレッドを複数用意し、
 *    「自分の質問」の編集・解決マーク動線を固定アカウントで確認できるようにする。
 *
 * 依存順序: UserSeeder → CertificationSeeder → EnrollmentSeeder → 本 Seeder。
 */
final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $certifications = Certification::published()->get();
        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->where('email', '!=', 'student@certify-lms.test')
            ->inRandomOrder()
            ->limit(12)
            ->get();
        $coaches = User::query()->where('role', UserRole::Coach->value)->get();

        if ($certifications->isEmpty() || $students->isEmpty()) {
            return;
        }

        foreach ($certifications as $certification) {
            $this->seedThreadsForCertification($certification, $students, $coaches);
        }

        $fixedStudent = User::query()->where('email', 'student@certify-lms.test')->first();
        if ($fixedStudent !== null) {
            $this->seedFixedStudentThreads($fixedStudent, $certifications, $students, $coaches);
        }
    }

    /**
     * @param Collection<int, User> $students
     * @param Collection<int, User> $coaches
     */
    private function seedThreadsForCertification(Certification $certification, Collection $students, Collection $coaches): void
    {
        // 未解決: 未回答(0件) 1 件 + 対応中(回答あり) 1 件
        $this->createThread($certification, $students->random(), open: true, replyCount: 0, daysAgo: 2);
        $this->createThread($certification, $students->random(), open: true, replyCount: 2, daysAgo: 5, students: $students, coaches: $coaches);

        // 解決済: 回答少なめ 1 件 + 回答多め 1 件
        $this->createThread($certification, $students->random(), open: false, replyCount: 1, daysAgo: 12, students: $students, coaches: $coaches);
        $this->createThread($certification, $students->random(), open: false, replyCount: 5, daysAgo: 20, students: $students, coaches: $coaches);
    }

    /**
     * @param Collection<int, Certification> $certifications
     * @param Collection<int, User> $students
     * @param Collection<int, User> $coaches
     */
    private function seedFixedStudentThreads(User $fixedStudent, Collection $certifications, Collection $students, Collection $coaches): void
    {
        $targets = $certifications->take(2);

        foreach ($targets as $index => $certification) {
            $this->createThread(
                $certification,
                $fixedStudent,
                open: $index % 2 === 0,
                replyCount: $index % 2 === 0 ? 0 : 3,
                daysAgo: 3 + $index * 4,
                students: $students,
                coaches: $coaches,
            );
        }
    }

    /**
     * @param Collection<int, User>|null $students 回答者候補(受講生)。null の場合は回答を作らない
     * @param Collection<int, User>|null $coaches 回答者候補(コーチ)。null の場合は回答を作らない
     */
    private function createThread(
        Certification $certification,
        User $author,
        bool $open,
        int $replyCount,
        int $daysAgo,
        ?Collection $students = null,
        ?Collection $coaches = null,
    ): void {
        $createdAt = now()->subDays($daysAgo)->subHours(random_int(0, 23));

        $thread = QaThread::factory()
            ->for($certification)
            ->for($author)
            ->state($open ? ['status' => QaThreadStatus::Open->value, 'resolved_at' => null] : [
                'status' => QaThreadStatus::Resolved->value,
                'resolved_at' => $createdAt->copy()->addDays(min($daysAgo, 3)),
            ])
            ->create();

        $thread->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        if ($replyCount > 0 && $students !== null && $coaches !== null) {
            $responders = $students->concat($coaches)->reject(fn (User $u) => $u->is($author));

            for ($i = 0; $i < $replyCount; $i++) {
                $responder = $responders->isNotEmpty() ? $responders->random() : $author;
                $replyCreatedAt = $createdAt->copy()->addHours($i + 1);

                $reply = $thread->replies()->create([
                    'user_id' => $responder->id,
                    'body' => fake()->paragraph(),
                ]);
                $reply->forceFill(['created_at' => $replyCreatedAt, 'updated_at' => $replyCreatedAt])->save();
            }
        }
    }
}
