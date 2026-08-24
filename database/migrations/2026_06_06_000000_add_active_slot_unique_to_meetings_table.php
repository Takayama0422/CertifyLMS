<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * meetings テーブル作成時のコメントは (coach_id, scheduled_at) UNIQUE を前提としていたが、
 * 実装が漏れており同時刻二重予約を防げていなかった。本 Migration で不足していた排他制御を追加する。
 *
 * (coach_id, scheduled_at) へ status を問わない素の UNIQUE を張る。これは meetings テーブル
 * 作成時のコメント・例外処理(UniqueConstraintViolationException → MeetingNoAvailableCoachException)
 * が前提としていた支給側の確定した設計であり、キャンセル済みの枠が同じ (coach_id, scheduled_at) では
 * 再予約できないことは意図された挙動である(実際、受入テスト
 * MeetingControllerTest::test_store_blocks_double_booking_for_same_coach_and_slot が
 * 「canceled 済みの枠でも同時刻の新規予約は阻止される」ことを検証している)。
 */
return new class extends Migration
{
    public function up(): void
    {
        // UNIQUE 制約を初めて敷くため、既存データに「同コーチ×同時刻で reserved/completed が
        // 複数」という不正重複行があれば ALTER TABLE 自体が失敗する。先に解消しておく。
        $this->resolveDuplicateActiveBookings();

        Schema::table('meetings', function (Blueprint $table) {
            $table->unique(['coach_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique(['coach_id', 'scheduled_at']);
        });
    }

    /**
     * 稼働中の環境には、UNIQUE 未実装のまま許容されていた「同コーチ×同時刻の reserved/completed
     * 二重予約」が既に存在し得る。各重複グループについて最も早く成立した 1 件(id は ULID のため
     * 作成時刻順に昇順)だけを正として残し、それ以外は「本来 409 で弾かれるべきだった予約」として
     * canceled 扱いにする。
     *
     * 面談回数の返却(RefundQuotaAction 相当の消費トランザクション調整)は業務判断を伴うため
     * 本 Migration では行わない。対象があった場合はログ出力し、運用側で該当受講生の残数を
     * 個別に確認・調整する。
     */
    private function resolveDuplicateActiveBookings(): void
    {
        $duplicateGroups = DB::table('meetings')
            ->select('coach_id', 'scheduled_at')
            ->whereIn('status', ['reserved', 'completed'])
            ->groupBy('coach_id', 'scheduled_at')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('meetings')
                ->where('coach_id', $group->coach_id)
                ->where('scheduled_at', $group->scheduled_at)
                ->whereIn('status', ['reserved', 'completed'])
                ->orderBy('id')
                ->pluck('id');

            $loserIds = $ids->slice(1)->all();

            if ($loserIds === []) {
                continue;
            }

            DB::table('meetings')
                ->whereIn('id', $loserIds)
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                    'updated_at' => now(),
                ]);

            logger()->warning(
                'meetings: UNIQUE(coach_id, scheduled_at) 追加のため重複予約を canceled 化した。'.
                '面談回数の返却要否を個別に確認すること。',
                ['coach_id' => $group->coach_id, 'scheduled_at' => $group->scheduled_at, 'canceled_ids' => $loserIds],
            );
        }
    }
};
