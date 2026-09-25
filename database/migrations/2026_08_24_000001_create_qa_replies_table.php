<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 質問掲示板の回答テーブル。
 *
 * qa_thread_id は cascadeOnDelete: 自己送信削除(投稿者本人)は回答 0 件のときのみ許可するため通常は発火しないが、
 * 管理者モデレーションは回答付きスレッドも削除できる仕様のため、その経路で配下回答を道連れにする安全弁として持たせる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('qa_thread_id')->constrained('qa_threads')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['qa_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_replies');
    }
};
