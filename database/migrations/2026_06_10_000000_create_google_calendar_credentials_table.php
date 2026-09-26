<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コーチ 1 人につき 1 件の Google カレンダー連携情報(OAuth トークン)を保持するテーブル。
 *
 * user_id を UNIQUE にすることで「連携は 1 コーチ 1 アカウントのみ」を DB 制約として保証する
 * (`User::googleCredential()` は hasOne で対応)。プライマリカレンダー固定のためカレンダー選択 UI は
 * 持たず、`calendar_id` は常に 'primary' を保存する(スキーマ上は将来の拡張に備え保持)。
 *
 * 認証情報の暗号化保存は本チケットのスコープ外(README に本番運用時の暗号化推奨を明記する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('calendar_id')->default('primary');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_credentials');
    }
};
