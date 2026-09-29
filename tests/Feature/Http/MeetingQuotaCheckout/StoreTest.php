<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuotaCheckout;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Contracts\PaymentGatewayContract;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * POST /meeting-quota/checkout(購入実行、決済サービスへリダイレクト)。実通信は発生させない。
 */
#[Group('external')]
#[Group('stripe')]
class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_student_is_redirected_to_checkout_url(): void
    {
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayContract::class, $fake);

        $student = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('checkout.stripe.test', $response->headers->get('Location'));

        $payment = Payment::sole();
        $this->assertSame($student->id, $payment->user_id);
        $this->assertSame($plan->id, $payment->meeting_pack_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_cannot_purchase_draft_pack_even_with_direct_id(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->inProgress()->create();
        $draftPack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $draftPack->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_cannot_purchase_archived_pack_even_with_direct_id(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->inProgress()->create();
        $archivedPack = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $archivedPack->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_graduated_student_cannot_purchase(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->graduated()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_withdrawn_student_cannot_purchase(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->withdrawn()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_invited_student_cannot_purchase(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->invited()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_coach_cannot_purchase(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $coach = User::factory()->coach()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($coach)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $response->assertForbidden();
    }

    public function test_nonexistent_pack_id_is_rejected(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->postJson(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => 'not-a-real-id',
        ]);

        $response->assertStatus(422);
    }

    public function test_cancelling_checkout_does_not_change_remaining_quota(): void
    {
        // 中断/キャンセル時は決済サービスから完了通知が来ないため、Payment は pending のまま残数へ影響しない。
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 0]);
        $plan = MeetingPack::factory()->published()->create();

        $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $plan->id,
        ]);

        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($student));
        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
    }
}
