<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 *
 * 既存データの重複解消・自動調整は PM 判断で本チケットの対象外(DB 制約追加のみが対象)。
 * 稼働中データに重複がある場合、本 Migration の up() は失敗する。その解消は本チケットの範囲外として
 * 運用側の判断に委ねる。
 */
return new class extends Migration
{
    public function up(): void
    {
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
};
