<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel 標準の通知テーブル。`Illuminate\Notifications\DatabaseNotification` がそのまま前提とする形。
 *
 * `users.id` が ULID(文字列)のため、既定の `morphs('notifiable')`(notifiable_id が
 * unsignedBigInteger になる)ではなく `ulidMorphs('notifiable')` を使う。ここを既定の
 * morphs のままにすると、通知作成時に notifiable_id の型が一致せず登録できない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
