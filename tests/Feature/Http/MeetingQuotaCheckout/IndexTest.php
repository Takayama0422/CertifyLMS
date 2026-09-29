<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuotaCheckout;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /meeting-quota/checkout(購入選択画面)。学習中の受講生のみ。
 */
class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_student_sees_only_published_packs(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = MeetingPack::factory()->published()->create(['name' => '公開中パック']);
        MeetingPack::factory()->draft()->create(['name' => '下書きパック']);
        MeetingPack::factory()->archived()->create(['name' => 'アーカイブ済パック']);

        $response = $this->actingAs($student)->get(route('meeting-quota.checkout.select'));

        $response->assertOk();
        $response->assertSee('公開中パック');
        $response->assertDontSee('下書きパック');
        $response->assertDontSee('アーカイブ済パック');
    }

    public function test_graduated_student_cannot_access(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_withdrawn_student_cannot_access(): void
    {
        $student = User::factory()->student()->withdrawn()->create();

        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_invited_student_cannot_access(): void
    {
        $student = User::factory()->student()->invited()->create();

        $this->actingAs($student)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_coach_cannot_access(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $this->actingAs($coach)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_admin_cannot_access(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();

        $this->actingAs($admin)
            ->get(route('meeting-quota.checkout.select'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('meeting-quota.checkout.select'))
            ->assertRedirect(route('login'));
    }
}
