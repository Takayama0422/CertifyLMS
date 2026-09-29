<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者お知らせ配信(S-B-08)の配信履歴テーブル。
 *
 * 配信は即時 1 回のみで、再配信 / 編集 / 取消の経路を持たない(要件シート S2/S8)ため、
 * 状態カラムは持たず「配信した事実」をそのまま記録する。`dispatched_at` / `dispatched_count` の
 * カラム名は支給済み管理画面(`resources/views/announcement/management/*.blade.php`)が
 * 実プロパティとして参照している名前に合わせている。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->text('body');
            $table->string('target_type');
            $table->foreignUlid('target_certification_id')->nullable()->constrained('certifications')->nullOnDelete();
            $table->foreignUlid('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->timestamp('dispatched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
