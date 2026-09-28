<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meeting_quota_transactions.related_payment_id への FK を追加する。
 *
 * 元の Migration(create_meeting_quota_transactions_table)は「payments テーブル(追加面談購入 Feature 所有)
 * 未作成のため FK 制約は付けない(payments 導入時に追加する)」とコメントされていた。本 Migration がその「導入時」にあたる。
 *
 * meetings テーブルの FK 追加 Migration(add_related_meeting_fk_to_meeting_quota_transactions)と同じ流儀で、
 * payments テーブルが存在しない環境(本 Feature 単体で migrate:fresh する場合等)では追加をスキップする。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->foreign('related_payment_id')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
        });
    }
};
