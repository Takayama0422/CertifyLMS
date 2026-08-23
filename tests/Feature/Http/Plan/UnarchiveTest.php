<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnarchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_unarchives_to_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.unarchive', $plan));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $this->assertSame('draft', $plan->fresh()->status->value);
    }

    public function test_cannot_unarchive_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.plans.unarchive', $plan));

        $response->assertStatus(409);
    }

    public function test_cannot_unarchive_published(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.plans.unarchive', $plan));

        $response->assertStatus(409);
    }

    public function test_coach_cannot_unarchive(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->archived()->create();

        $response = $this->actingAs($coach)->post(route('admin.plans.unarchive', $plan));

        $response->assertForbidden();
    }
}
