<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 相談(Gemini AI チャットボット, S-A-02)の会話。
 *
 * - オーナーは常に受講生本人(user_id)。他ロールは利用不可。
 * - enrollment_id / section_id は「今いる文脈」の自動付与用(いずれも任意 = 全般相談も許容)。
 *   Section が削除されても会話自体は残す(nullOnDelete)ため、Enrollment / Section の削除で
 *   本テーブルの行が消えることはない。
 * - (user_id, section_id) は一意制約。同じ受講生 × 同じ教材の会話を乱立させない要件を DB でも
 *   担保する(section_id が NULL の一般相談同士は、NULL 同士を別物として扱う DB の一意制約の挙動
 *   どおり何件でも作成できる)。
 * - title は AI 自動生成(初回応答後 1 回)または受講生の手動編集で更新される。
 *   title_manually_set = true になった会話は以降 AI による自動改題の対象から外す。
 * - last_message_at は一覧の並び順(今日 / 過去 7 日 / 過去 30 日のグルーピング)に使う非正規化カラム。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUlid('enrollment_id')
                ->nullable()
                ->constrained('enrollments')
                ->nullOnDelete();
            $table->foreignUlid('section_id')
                ->nullable()
                ->constrained('sections')
                ->nullOnDelete();
            $table->string('title', 100);
            $table->boolean('title_manually_set')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->unique(['user_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
