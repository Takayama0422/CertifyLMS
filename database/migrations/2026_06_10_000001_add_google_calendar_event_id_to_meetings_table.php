<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 連携済コーチの面談成立時に自動登録した Google カレンダーイベントの ID を保持する列。
 *
 * キャンセル時にこの ID を使って Google 側のイベントを削除する(未連携 or 登録失敗時は null のまま)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('google_calendar_event_id')->nullable()->after('meeting_url_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('google_calendar_event_id');
        });
    }
};
