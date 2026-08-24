<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // 面談パックが後から改廃(価格変更 / アーカイブ / 削除)されても購入時点の値を監査できるよう、
            // meeting_pack_id は nullOnDelete にし、amount / quantity は購入時点のスナップショットとして別途保持する。
            $table->foreignUlid('meeting_pack_id')->nullable()->constrained('meeting_packs')->nullOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('quantity');
            // Stripe Checkout Session ID。Webhook 側の冪等性判定の主キー(同一 Session への重複通知を無視する)。
            $table->string('stripe_checkout_session_id', 255)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
