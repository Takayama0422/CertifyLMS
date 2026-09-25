<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite は外部キー制約の DROP/ADD に対応していない（テーブル再作成が要る）ため、
     * テスト用の SQLite 接続では何もしない。テスト実行時は foreign_key_constraints が
     * 無効（config/database.php）で SQLite 側は制約自体を強制していないため実害は無い。
     * 挙動の最終判定は MySQL（本番相当）で行う。
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('user_plan_logs', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
        });

        DB::statement('ALTER TABLE user_plan_logs MODIFY plan_id CHAR(26) NULL');

        Schema::table('user_plan_logs', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('user_plan_logs', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
        });

        DB::statement('ALTER TABLE user_plan_logs MODIFY plan_id CHAR(26) NOT NULL');

        Schema::table('user_plan_logs', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
        });
    }
};
