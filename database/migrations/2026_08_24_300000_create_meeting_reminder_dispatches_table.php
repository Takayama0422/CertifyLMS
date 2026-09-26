<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 面談リマインダー(S-B-09)の重複配信防止テーブル。
 *
 * 「(面談, 配信窓, 受信者)」の組を UNIQUE 制約で 1 度しか作れないようにし、これを
 * 配信前に INSERT する「予約」として使う。INSERT が成功した場合のみ通知を送るため、
 * 定期実行の重複起動・再実行があっても DB レベルで二重配信を防げる(要件シート S7 の肝)。
 *
 * `sent_at` は「予約はしたが配信に成功したか」を区別するための列(レビュー指摘対応)。
 * 予約(INSERT)直後は null で、通知送信に成功した時点で now() を書き込む。送信が失敗した行は
 * 即座に削除して次回実行での再送を許可し、削除される前にプロセスごと落ちた場合(sent_at が
 * null のまま取り残された行)は一定時間経過後に再利用可能な「取りこぼし」とみなす
 * (`SendMeetingRemindersAction` 参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminder_dispatches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('window');
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'window', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_dispatches');
    }
};
