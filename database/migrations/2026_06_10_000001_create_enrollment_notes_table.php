<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コーチメモテーブル(1 Enrollment : N EnrollmentNote)。
 *
 * 担当コーチ / 管理者が受講登録単位で記録する業務記録。作成者(user_id)は複数コーチ体制での
 * 「自分のメモのみ編集/削除可」判定に使う。受講生には一切見せない(閲覧含め全拒否)。
 * 削除は物理削除(履歴は残さない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['enrollment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_notes');
    }
};
