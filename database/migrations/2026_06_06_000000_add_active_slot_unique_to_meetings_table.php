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
 * 素朴に (coach_id, scheduled_at) へ UNIQUE を張ると status を問わず衝突するため、
 * 一度 canceled になった枠が同じ (coach_id, scheduled_at) では二度と予約できなくなる
 * (キャンセル後の再予約が不可能になる一方、予約画面の空き枠表示は canceled を空きとして
 * 扱っているため、表示上は空いているのに常に 409 になる矛盾が生じる)。
 *
 * そこで「canceled のときだけ NULL になる」生成列 active_scheduled_at を挟み、
 * (coach_id, active_scheduled_at) に UNIQUE を張る。MySQL の UNIQUE 制約は NULL 同士を
 * 別値として扱うため、複数の canceled 行が同じ (coach_id, scheduled_at) を持っていても
 * 衝突しない。これにより:
 *   (a) reserved/completed 同士の同時刻二重予約は従来どおり DB レベルで禁止される
 *   (b) canceled 済みの枠は同じ (coach_id, scheduled_at) へ再度予約できる
 *   (c) 予約画面 / 自動割当のコーチ抽出は元から reserved・completed のみを「予約済」として
 *       除外しており(canceled は空き扱い)、この制約と前提が一致する
 * を同時に満たす。
 */
return new class extends Migration
{
    public function up(): void
    {
        // UNIQUE 制約を初めて敷くため、既存データに「同コーチ×同時刻で reserved/completed が
        // 複数」という不正重複行があれば ALTER TABLE 自体が失敗する。先に解消しておく。
        $this->resolveDuplicateActiveBookings();

        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('active_scheduled_at')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'canceled' THEN NULL ELSE scheduled_at END")
                ->after('scheduled_at');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->unique(['coach_id', 'active_scheduled_at'], 'meetings_coach_active_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_coach_active_slot_unique');
            $table->dropColumn('active_scheduled_at');
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
                'meetings: UNIQUE(coach_id, active_scheduled_at) 追加のため重複予約を canceled 化した。'.
                '面談回数の返却要否を個別に確認すること。',
                ['coach_id' => $group->coach_id, 'scheduled_at' => $group->scheduled_at, 'canceled_ids' => $loserIds],
            );
        }
    }
};
