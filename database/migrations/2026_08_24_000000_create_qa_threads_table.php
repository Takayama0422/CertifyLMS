<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問掲示板のスレッド(質問)テーブル。
 *
 * `status` は `App\Enums\QaThreadStatus`(open/resolved)を文字列で持ち、`resolved_at` と対で更新する
 * (`status` は一覧の絞り込み・件数集計クエリで直接 WHERE 条件に使うため実カラムとして持つ。
 * `resolved_at` は解決日時の表示用)。物理削除のみ(削除履歴は保持しない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('certification_id')->constrained('certifications')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 20)->default('open');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            // 一覧の絞り込み(資格 × 解決状態)・新着順取得を高速化する補助 INDEX
            $table->index(['certification_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_threads');
    }
};
