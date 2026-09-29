<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuotaCheckout;

use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /meeting-quota/success(決済完了画面)。学習中の受講生のみ。
 */
class SuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_purchase_summary_when_session_id_matches_own_payment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->create(['name' => '5 回パック']);
        Payment::factory()->for($student)->for($plan, 'meetingPack')->succeeded()->create([
            'stripe_checkout_session_id' => 'cs_test_success_1',
            'quantity' => 5,
            'amount' => 12000,
        ]);

        $response = $this->actingAs($student)->get(
            route('meeting-quota.success', ['session_id' => 'cs_test_success_1']),
        );

        $response->assertOk();
        $response->assertSee('5 回パック');
    }

    public function test_shows_generic_completion_page_without_session_id(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->get(route('meeting-quota.success'))->assertOk();
    }

    public function test_cannot_see_another_users_payment_by_guessing_session_id(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $intruder = User::factory()->student()->inProgress()->create();
        $plan = MeetingPack::factory()->published()->create(['name' => '他人のパック名'.uniqid()]);
        Payment::factory()->for($owner)->for($plan, 'meetingPack')->succeeded()->create([
            'stripe_checkout_session_id' => 'cs_test_owner_only',
        ]);

        $response = $this->actingAs($intruder)->get(
            route('meeting-quota.success', ['session_id' => 'cs_test_owner_only']),
        );

        $response->assertOk();
        $response->assertDontSee($plan->name);
    }

    public function test_graduated_student_cannot_access(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)->get(route('meeting-quota.success'))->assertForbidden();
    }

    public function test_withdrawn_student_cannot_access(): void
    {
        $student = User::factory()->student()->withdrawn()->create();

        $this->actingAs($student)->get(route('meeting-quota.success'))->assertForbidden();
    }

    public function test_invited_student_cannot_access(): void
    {
        $student = User::factory()->student()->invited()->create();

        $this->actingAs($student)->get(route('meeting-quota.success'))->assertForbidden();
    }
}
