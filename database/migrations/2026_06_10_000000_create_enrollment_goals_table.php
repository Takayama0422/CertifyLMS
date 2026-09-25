<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個人学習目標テーブル(1 Enrollment : N EnrollmentGoal)。
 *
 * 受講生本人のみが CRUD する自由入力の目標。達成状況は achieved_at の有無のみで表現し
 * (達成 → 解除 → 達成…の履歴は持たない)、削除は物理削除(履歴は残さない)。
 * 親 Enrollment は SoftDelete のため、この DB 制約の cascadeOnDelete は forceDelete 時の保険であり、
 * 通常の受講解除(SoftDelete)時の連動削除はアプリケーション側(Enrollment\DestroyAction)で行う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->cascadeOnDelete();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->date('target_date');
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'achieved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_goals');
    }
};
