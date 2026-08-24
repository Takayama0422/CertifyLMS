<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * B-A-01: 面談予約の並行リクエストで同一コーチ・同一時刻枠が二重予約されないことを検証する。
 *
 * PHPUnit の feature テストは 1 プロセス・1 DB コネクション内で逐次実行されるため、
 * `$this->post()` を 2 回連続で呼んでも真の並行アクセス(2 コネクションが同時に
 * INSERT を試みる race condition)は再現できない。本テストは Symfony Process で
 * 実 OS プロセスを 2 つ起動し、それぞれ独立した DB コネクションで
 * `MeetingController::store()` を同時刻バリアで同期して実行することで、
 * (coach_id, scheduled_at) UNIQUE 制約による排他を実際の競合状態で検証する。
 *
 * RefreshDatabase が張るトランザクションはワーカープロセスからは不可視(未コミットのため)
 * なので、Arrange 直後に明示 commit して他プロセスから見える状態にし、
 * tearDown で作成した行を手動削除して後続テストに影響を残さない。
 */
class MeetingBookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ?string $studentAId = null;

    private ?string $studentBId = null;

    private ?string $coachId = null;

    private ?string $adminId = null;

    private ?string $certificationId = null;

    /**
     * CertificationFactory が category_id 用に暗黙生成する CertificationCategory の ID。
     * 後始末で個別に削除しないと、同一プロセスの後続テストに孤児行が残り続ける。
     */
    private ?string $certificationCategoryId = null;

    /**
     * CertificationFactory が created_by_user_id 用に暗黙生成する admin の ID。
     * 明示的に作った $adminId とは別人物のため、後始末で個別に追跡する。
     */
    private ?string $certificationCreatorUserId = null;

    protected function tearDown(): void
    {
        // Arrange 時点で明示 commit しているため、ここで作成した行を手動で後始末する
        // (RefreshDatabase 側の rollBack は commit 済みトランザクションに対しては何もしない)。
        if ($this->certificationId !== null && $this->coachId !== null) {
            DB::table('certification_coach_assignments')
                ->where('certification_id', $this->certificationId)
                ->where('user_id', $this->coachId)
                ->delete();
        }

        $studentIds = array_values(array_filter([$this->studentAId, $this->studentBId]));
        if ($studentIds !== []) {
            MeetingQuotaTransaction::query()->whereIn('user_id', $studentIds)->delete();
            Meeting::query()->whereIn('student_id', $studentIds)->delete();
            Enrollment::withTrashed()->whereIn('user_id', $studentIds)->forceDelete();
        }

        if ($this->coachId !== null) {
            CoachAvailability::query()->where('coach_id', $this->coachId)->delete();
        }

        // certifications.category_id は restrictOnDelete のため、先に certification を消してから消す。
        if ($this->certificationId !== null) {
            Certification::query()->whereKey($this->certificationId)->delete();
        }

        if ($this->certificationCategoryId !== null) {
            CertificationCategory::query()->whereKey($this->certificationCategoryId)->delete();
        }

        $userIds = array_values(array_unique(array_filter([
            $this->studentAId,
            $this->studentBId,
            $this->coachId,
            $this->adminId,
            $this->certificationCreatorUserId,
        ])));
        if ($userIds !== []) {
            User::withTrashed()->whereIn('id', $userIds)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * 2 プロセスが「同じ瞬間に本処理へ入った」とみなす許容差(秒)。
     * バリア待ちの解像度(usleep 500µs)に対して十分大きく、かつ「片方が Laravel の
     * bootstrap に手間取ってバリアを外し、他方が完了した後に逐次実行された」場合の
     * ずれ(HTTP 相当の処理 1 回分、通常数十ms〜)は確実に検出できる値として 300ms を採る。
     */
    private const CONCURRENT_ENTRY_TOLERANCE_SECONDS = 0.3;

    public function test_concurrent_store_requests_for_same_coach_and_slot_only_reserve_one(): void
    {
        // Arrange: 同一資格に 2 名の受講生 + 単一の担当コーチ(各時刻枠は 1 名分の空きしかない)
        $studentA = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $studentB = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $this->studentAId = $studentA->id;
        $this->studentBId = $studentB->id;
        $this->adminId = $admin->id;
        $this->coachId = $coach->id;

        $certification = Certification::factory()->published()->create();
        $this->certificationId = $certification->id;
        $this->certificationCategoryId = $certification->category_id;
        $this->certificationCreatorUserId = $certification->created_by_user_id;
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        $enrollmentA = Enrollment::factory()->for($studentA, 'user')->for($certification)->learning()->create();
        $enrollmentB = Enrollment::factory()->for($studentB, 'user')->for($certification)->learning()->create();

        // Arrange 完了分を実コミットし、別プロセスの DB コネクションから見えるようにする
        DB::commit();

        // 単発の勝敗判定だけでは「たまたま逐次実行になったが結果だけは 1 勝 1 敗だった」偶然を
        // 排除できない。時刻をずらしながら複数回競合させ、毎回について「本当に同時に本処理へ
        // 入ったこと」まで確認することで、排他が外れていれば確実に検出できるようにする。
        $winsA = 0;
        $winsB = 0;
        $mondayBase = now()->startOfDay()->next(Carbon::MONDAY);

        foreach (range(0, 2) as $round) {
            $scheduledAt = $mondayBase->copy()->setTime(10 + $round, 0);
            [$resultA, $resultB, $enteredAtA, $enteredAtB] = $this->raceStoreRequests(
                $studentA,
                $enrollmentA,
                "相談したい(A-{$round})",
                $studentB,
                $enrollmentB,
                "相談したい(B-{$round})",
                $scheduledAt,
            );

            $entryGap = abs($enteredAtA - $enteredAtB);
            $this->assertLessThanOrEqual(
                self::CONCURRENT_ENTRY_TOLERANCE_SECONDS,
                $entryGap,
                "round {$round}: 2 プロセスが本処理へ入った時刻が {$entryGap}秒 ずれている。".
                '逐次実行になっており、真の競合を検証できていない疑いがある',
            );

            $results = [$resultA, $resultB];
            $oks = array_filter($results, fn ($r) => $r === 'OK');
            $conflicts = array_filter($results, fn ($r) => str_contains($r, 'MeetingNoAvailableCoachException'));

            // Assert: 2 リクエストのうち成立するのは 1 件のみ、もう 1 件は 409 相当の空きコーチなしエラー
            $this->assertCount(
                1,
                $oks,
                "round {$round}: 並行予約で 2 件とも成立、または 2 件とも失敗した(A={$resultA} / B={$resultB})。".
                '(coach_id, active_scheduled_at) UNIQUE による排他が効いていない',
            );
            $this->assertCount(
                1,
                $conflicts,
                "round {$round}: 敗者側が MeetingNoAvailableCoachException(409) 以外で終わった".
                "(A={$resultA} / B={$resultB})",
            );

            // Assert: コーチの面談一覧に同時刻の reserved が 1 件しか無い(ダブルブッキングなし)
            $this->assertSame(
                1,
                Meeting::query()
                    ->where('coach_id', $coach->id)
                    ->where('scheduled_at', $scheduledAt)
                    ->where('status', MeetingStatus::Reserved->value)
                    ->count(),
                "round {$round}: 同時刻に reserved が 1 件ではない",
            );

            if ($resultA === 'OK') {
                $winsA++;
            } else {
                $winsB++;
            }
        }

        // Assert: 面談回数の消費は各ラウンドの勝者側のみ 1 回、敗者側は消費されない(二重消費/消費残りなし)
        $consumedCount = MeetingQuotaTransaction::query()
            ->whereIn('user_id', [$studentA->id, $studentB->id])
            ->where('type', MeetingQuotaTransactionType::Consumed->value)
            ->count();
        $this->assertSame(3, $consumedCount);
        $this->assertSame(3, $winsA + $winsB);

        $quotaService = app(MeetingQuotaService::class);
        $this->assertSame(5 - $winsA, $quotaService->remaining($studentA->fresh()));
        $this->assertSame(5 - $winsB, $quotaService->remaining($studentB->fresh()));
    }

    /**
     * $studentA / $studentB それぞれの enrollment から、同じ $scheduledAt へ向けて
     * 実 OS プロセスを 2 つ同時起動し、`MeetingController::store()` を競合させる。
     *
     * @return array{0: string, 1: string, 2: float, 3: float} [resultA, resultB, enteredAtA, enteredAtB]
     */
    private function raceStoreRequests(
        User $studentA,
        Enrollment $enrollmentA,
        string $topicA,
        User $studentB,
        Enrollment $enrollmentB,
        string $topicB,
        Carbon $scheduledAt,
    ): array {
        $scheduledAtIso = $scheduledAt->format('Y-m-d\TH:i:s');

        $barrierFile = tempnam(sys_get_temp_dir(), 'meeting-barrier-');
        $outFileA = tempnam(sys_get_temp_dir(), 'meeting-out-a-');
        $outFileB = tempnam(sys_get_temp_dir(), 'meeting-out-b-');
        // 2 プロセスがプロセス起動オーバーヘッド(Laravel bootstrap)を終えてから同時刻に本処理へ入るよう、
        // 少し先の絶対時刻をバリアとして共有する
        file_put_contents($barrierFile, (string) (microtime(true) + 1.0));

        $workerScript = base_path('tests/Support/concurrent_meeting_booking_worker.php');

        $processA = new Process([
            PHP_BINARY, $workerScript,
            $studentA->id, $enrollmentA->id, $scheduledAtIso, $topicA, $barrierFile, $outFileA,
        ]);
        $processB = new Process([
            PHP_BINARY, $workerScript,
            $studentB->id, $enrollmentB->id, $scheduledAtIso, $topicB, $barrierFile, $outFileB,
        ]);
        $processA->setTimeout(30);
        $processB->setTimeout(30);

        // Act: ほぼ同時に 2 プロセスを起動し、両方の完了を待つ(真の並行実行)
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $this->assertTrue(
            $processA->isSuccessful(),
            "worker A が異常終了した: stdout={$processA->getOutput()} stderr={$processA->getErrorOutput()}",
        );
        $this->assertTrue(
            $processB->isSuccessful(),
            "worker B が異常終了した: stdout={$processB->getOutput()} stderr={$processB->getErrorOutput()}",
        );

        [$enteredAtA, $resultA] = explode(PHP_EOL, (string) file_get_contents($outFileA), 2);
        [$enteredAtB, $resultB] = explode(PHP_EOL, (string) file_get_contents($outFileB), 2);
        @unlink($barrierFile);
        @unlink($outFileA);
        @unlink($outFileB);

        return [$resultA, $resultB, (float) $enteredAtA, (float) $enteredAtB];
    }
}
