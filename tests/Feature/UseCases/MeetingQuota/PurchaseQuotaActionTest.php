<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\PurchaseQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseQuotaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserts_purchased_transaction_with_payment_quantity(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->succeeded()->create(['quantity' => 5]);

        $tx = app(PurchaseQuotaAction::class)($payment);

        $this->assertSame(MeetingQuotaTransactionType::Purchased, $tx->type);
        $this->assertSame(5, $tx->amount);
        $this->assertSame($payment->id, $tx->related_payment_id);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $user->id,
            'type' => 'purchased',
            'amount' => 5,
            'related_payment_id' => $payment->id,
        ]);
    }

    public function test_remaining_quota_reflects_purchase_immediately(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->succeeded()->create(['quantity' => 3]);

        app(PurchaseQuotaAction::class)($payment);

        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user));
    }
}
