<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_meeting_pack_and_user(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $user = User::factory()->student()->create();
        $payment = Payment::factory()->for($pack, 'meetingPack')->for($user)->create();

        $this->assertTrue($payment->meetingPack->is($pack));
        $this->assertTrue($payment->user->is($user));
    }

    public function test_status_is_cast_to_enum(): void
    {
        $payment = Payment::factory()->succeeded()->create();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
    }

    public function test_meeting_pack_can_be_null_after_pack_deletion(): void
    {
        $pack = MeetingPack::factory()->archived()->create();
        $payment = Payment::factory()->for($pack, 'meetingPack')->succeeded()->create();

        $pack->delete();

        $this->assertNull($payment->fresh()->meeting_pack_id);
        $this->assertNull($payment->fresh()->meetingPack);
    }

    public function test_amount_and_quantity_are_cast_to_integer(): void
    {
        $payment = Payment::factory()->create(['amount' => '12000', 'quantity' => '5']);

        $this->assertSame(12000, $payment->amount);
        $this->assertSame(5, $payment->quantity);
    }
}
