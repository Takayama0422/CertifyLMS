<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)->get(route('admin.plans.show', $plan));

        $response->assertOk();
        $response->assertViewIs('plan.management.show');
        $response->assertSee($plan->name);
    }

    public function test_show_lists_linked_users(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->student()->create(['plan_id' => $plan->id, 'name' => '受講者太郎']);

        $response = $this->actingAs($admin)->get(route('admin.plans.show', $plan));

        $response->assertOk();
        $response->assertSee('受講者太郎');
    }

    public function test_coach_cannot_view_detail(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.plans.show', $plan))
            ->assertForbidden();
    }

    public function test_student_cannot_view_detail(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->published()->create();

        $this->actingAs($student)
            ->get(route('admin.plans.show', $plan))
            ->assertForbidden();
    }

    public function test_create_form_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $this->actingAs($admin)->get(route('admin.plans.create'))->assertOk();
        $this->actingAs($coach)->get(route('admin.plans.create'))->assertForbidden();
    }

    public function test_edit_form_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)->get(route('admin.plans.edit', $plan))->assertOk();
        $this->actingAs($coach)->get(route('admin.plans.edit', $plan))->assertForbidden();
    }
}
