<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_draft_plan_without_users(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan));

        $response->assertRedirect(route('admin.plans.index'));
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_cannot_delete_draft_plan_with_linked_user(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        User::factory()->student()->create(['plan_id' => $plan->id]);

        $response = $this->actingAs($admin)->deleteJson(route('admin.plans.destroy', $plan));

        $response->assertStatus(409);
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_cannot_delete_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)->deleteJson(route('admin.plans.destroy', $plan));

        $response->assertStatus(409);
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_cannot_delete_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $response = $this->actingAs($admin)->deleteJson(route('admin.plans.destroy', $plan));

        $response->assertStatus(409);
    }

    public function test_html_request_redirects_back_with_flash_error_on_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        User::factory()->student()->create(['plan_id' => $plan->id]);

        $response = $this->actingAs($admin)
            ->from(route('admin.plans.show', $plan))
            ->delete(route('admin.plans.destroy', $plan));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('error', '下書きかつ受講者が紐づいていないプランのみ削除できます。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_coach_cannot_delete(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($coach)->delete(route('admin.plans.destroy', $plan));

        $response->assertForbidden();
    }

    public function test_student_cannot_delete(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($student)->delete(route('admin.plans.destroy', $plan));

        $response->assertForbidden();
    }
}
