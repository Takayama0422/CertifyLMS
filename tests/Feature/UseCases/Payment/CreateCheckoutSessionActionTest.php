<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\Payment\PaymentGatewayUnavailableException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Contracts\PaymentGatewayContract;
use App\UseCases\Payment\CreateCheckoutSessionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * CreateCheckoutSessionAction の検証。実通信は発生させず、PaymentGatewayContract を
 * FakePaymentGateway に差し替えて Payment 行の作成 / スナップショット保存 / エラー時のロールバックを確認する。
 */
class CreateCheckoutSessionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_payment_with_plan_snapshot_and_session_id(): void
    {
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayContract::class, $fake);

        $user = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->withCount(5)->withPrice(12000)->create();

        $url = app(CreateCheckoutSessionAction::class)(
            $user,
            $plan,
            'https://app.test/meeting-quota/success',
            'https://app.test/meeting-quota/checkout',
        );

        $this->assertNotEmpty($url);
        $this->assertCount(1, $fake->calls);

        $payment = Payment::sole();
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame($plan->id, $payment->meeting_pack_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(12000, $payment->amount);
        $this->assertSame(5, $payment->quantity);
        $this->assertNotNull($payment->stripe_checkout_session_id);
    }

    public function test_price_change_after_purchase_does_not_affect_saved_snapshot(): void
    {
        $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

        $user = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->withCount(1)->withPrice(3000)->create();

        app(CreateCheckoutSessionAction::class)(
            $user,
            $plan,
            'https://app.test/meeting-quota/success',
            'https://app.test/meeting-quota/checkout',
        );

        // マスタ側を後から変更しても、保存済みの Payment のスナップショットは変わらない(監査要件)。
        $plan->update(['price' => 9999, 'meeting_count' => 99]);

        $payment = Payment::sole();
        $this->assertSame(3000, $payment->amount);
        $this->assertSame(1, $payment->quantity);
    }

    public function test_rolls_back_payment_row_when_gateway_fails(): void
    {
        $fake = new FakePaymentGateway;
        $fake->failNext();
        $this->app->instance(PaymentGatewayContract::class, $fake);

        $user = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->create();

        $this->expectException(PaymentGatewayUnavailableException::class);

        try {
            app(CreateCheckoutSessionAction::class)(
                $user,
                $plan,
                'https://app.test/meeting-quota/success',
                'https://app.test/meeting-quota/checkout',
            );
        } finally {
            $this->assertSame(0, Payment::query()->count());
        }
    }
}
