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
 *
 * 親 Enrollment 削除時、メモ行自体は業務記録として残し「一覧から除外」するだけ(物理削除しない)。
 * そのため enrollment_id は他の受講登録配下テーブル(certificates / section_progresses /
 * learning_sessions / chat_rooms 等)と同様に restrictOnDelete とする
 * (cascadeOnDelete は履歴を残さない enrollment_status_logs のみの扱い)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->restrictOnDelete();
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
