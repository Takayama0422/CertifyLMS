<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 相談(Gemini AI チャットボット, S-A-02)の発言 1 件分。
 *
 * - user_id は常に会話オーナー(受講生)を指す非正規化カラム(assistant 発言でも同じ値)。
 *   1 日あたりの送信回数上限を `role = user` で絞ってこのカラムだけで集計できるようにする目的。
 * - model / input_tokens / output_tokens / response_time_ms は運用観測用の内部記録
 *   (モデル名 / トークン数 / 応答時間)。受講生向け UI には出さない想定の内部メタデータ。
 * - error_detail は AI 応答失敗時の詳細(HTTP ステータス等)。受講生向けにはメッセージ本文側で
 *   汎用的なエラー文言に変換して表示する。
 * - 編集 / 削除エンドポイントは提供しない(append only)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_chat_conversation_id')
                ->constrained('ai_chat_conversations')
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('role', 20);
            $table->string('status', 20);
            $table->text('content');
            $table->text('error_detail')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_conversation_id', 'created_at']);
            $table->index(['user_id', 'role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
