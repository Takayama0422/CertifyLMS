<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Payment;

use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\Payment\HandleStripeWebhookAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Event;
use Tests\Support\StripeWebhookTestHelpers;
use Tests\TestCase;

/**
 * HandleStripeWebhookAction の検証(署名検証は Controller 側の責務のため、ここでは検証済みの
 * Event オブジェクトを直接渡して業務処理のみを検証する)。
 *
 * 冪等性(重複通知で残数が二重加算されない)・想定外の通知への耐性を最重要観点として網羅する。
 */
#[Group('external')]
#[Group('stripe')]
class HandleStripeWebhookActionTest extends TestCase
{
    use RefreshDatabase;
    use StripeWebhookTestHelpers;

    private function eventFrom(string $payload): Event
    {
        return Event::constructFrom(json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_checkout_completed_marks_payment_succeeded_and_grants_quota(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_abc123',
            'quantity' => 5,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_1', 'checkout.session.completed', [
            'id' => 'cs_test_abc123',
        ]);

        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pi_test_evt_1', $payment->stripe_payment_intent_id);
        $this->assertSame(5, app(MeetingQuotaService::class)->remaining($user));
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $user->id,
            'type' => 'purchased',
            'amount' => 5,
            'related_payment_id' => $payment->id,
        ]);
    }

    public function test_duplicate_notification_does_not_double_credit_quota(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_dup',
            'quantity' => 3,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_dup', 'checkout.session.completed', [
            'id' => 'cs_test_dup',
        ]);

        $action = app(HandleStripeWebhookAction::class);
        $action($this->eventFrom($payload));
        // Stripe のリトライ・再送を模して同じ内容の通知をもう一度処理する。
        $action($this->eventFrom($payload));
        $action($this->eventFrom($payload));

        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user));
        $this->assertSame(
            1,
            MeetingQuotaTransaction::query()
                ->where('related_payment_id', $payment->id)
                ->count(),
        );
    }

    public function test_unknown_session_id_is_ignored_without_error(): void
    {
        $payload = $this->stripeCheckoutEventPayload('evt_unknown', 'checkout.session.completed', [
            'id' => 'cs_test_does_not_exist',
        ]);

        // 例外を投げず正常終了することを確認する(想定外の通知への耐性)。
        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $this->assertSame(0, MeetingQuotaTransaction::query()->count());
    }

    public function test_unrecognized_event_type_is_ignored_without_error(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_other',
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_other', 'customer.subscription.created', [
            'id' => 'cs_test_other',
        ]);

        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_malformed_payload_missing_data_object_is_ignored_without_error(): void
    {
        $malformed = json_encode(['id' => 'evt_malformed', 'type' => 'checkout.session.completed']);

        app(HandleStripeWebhookAction::class)($this->eventFrom($malformed));

        $this->assertSame(0, MeetingQuotaTransaction::query()->count());
    }

    public function test_unpaid_session_is_not_treated_as_completed(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_unpaid',
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_unpaid', 'checkout.session.completed', [
            'id' => 'cs_test_unpaid',
            'payment_status' => 'unpaid',
        ]);

        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_checkout_expired_marks_payment_failed_without_affecting_quota(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->pending()->create([
            'stripe_checkout_session_id' => 'cs_test_expired',
            'quantity' => 5,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_expired', 'checkout.session.expired', [
            'id' => 'cs_test_expired',
        ]);

        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user));
    }

    public function test_already_succeeded_payment_is_not_reprocessed_by_expired_event(): void
    {
        $user = User::factory()->student()->create(['max_meetings' => 0]);
        $payment = Payment::factory()->for($user)->succeeded()->create([
            'stripe_checkout_session_id' => 'cs_test_late',
            'quantity' => 5,
        ]);

        $payload = $this->stripeCheckoutEventPayload('evt_late', 'checkout.session.expired', [
            'id' => 'cs_test_late',
        ]);

        app(HandleStripeWebhookAction::class)($this->eventFrom($payload));

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }
}
