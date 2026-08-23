<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '1 ヶ月プラン 4 回',
            'description' => '説明文',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
            'sort_order' => 1,
        ], $override);
    }

    public function test_admin_can_update_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['name' => 'Old Name']);

        $payload = [
            'name' => 'New Name',
            'description' => '更新後の説明',
            'duration_days' => 90,
            'default_meeting_quota' => 8,
            'sort_order' => 5,
        ];

        $response = $this->actingAs($admin)->put(route('admin.plans.update', $plan), $payload);

        $response->assertRedirect(route('admin.plans.show', $plan));
        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'New Name',
            'duration_days' => 90,
            'default_meeting_quota' => 8,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_is_unchanged_even_if_payload_includes_status(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $payload = [
            'name' => $plan->name,
            'duration_days' => $plan->duration_days,
            'default_meeting_quota' => $plan->default_meeting_quota,
            'status' => 'draft',
        ];

        $this->actingAs($admin)->put(route('admin.plans.update', $plan), $payload);

        $this->assertSame('published', $plan->fresh()->status->value);
    }

    public function test_name_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_name_max_length_is_100(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload(['name' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload(['name' => str_repeat('a', 100)]))
            ->assertSessionDoesntHaveErrors('name');
    }

    public function test_description_max_length_is_2000(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload(['description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('description');
    }

    #[DataProvider('durationDaysBoundaryCases')]
    public function test_duration_days_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->put(route('admin.plans.update', $plan), $this->payload(['duration_days' => $value]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('duration_days');
        } else {
            $response->assertSessionHasErrors('duration_days');
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function durationDaysBoundaryCases(): array
    {
        return [
            '0 は不可' => [0, false],
            '1 は可' => [1, true],
            '3650 は可' => [3650, true],
            '3651 は不可' => [3651, false],
        ];
    }

    #[DataProvider('meetingQuotaBoundaryCases')]
    public function test_default_meeting_quota_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->put(route('admin.plans.update', $plan), $this->payload(['default_meeting_quota' => $value]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('default_meeting_quota');
        } else {
            $response->assertSessionHasErrors('default_meeting_quota');
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function meetingQuotaBoundaryCases(): array
    {
        return [
            '-1 は不可' => [-1, false],
            '0 は可' => [0, true],
            '1000 は可' => [1000, true],
            '1001 は不可' => [1001, false],
        ];
    }

    #[DataProvider('sortOrderBoundaryCases')]
    public function test_sort_order_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->put(route('admin.plans.update', $plan), $this->payload(['sort_order' => $value]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('sort_order');
        } else {
            $response->assertSessionHasErrors('sort_order');
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function sortOrderBoundaryCases(): array
    {
        return [
            '-1 は不可' => [-1, false],
            '0 は可' => [0, true],
        ];
    }

    public function test_duration_days_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        $payload = $this->payload();
        unset($payload['duration_days']);

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $payload)
            ->assertSessionHasErrors('duration_days');
    }

    public function test_default_meeting_quota_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        $payload = $this->payload();
        unset($payload['default_meeting_quota']);

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $payload)
            ->assertSessionHasErrors('default_meeting_quota');
    }

    public function test_sort_order_is_kept_when_omitted(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create(['sort_order' => 7]);
        $payload = $this->payload();
        unset($payload['sort_order']);

        $this->actingAs($admin)->put(route('admin.plans.update', $plan), $payload);

        $this->assertSame(7, $plan->fresh()->sort_order);
    }

    public function test_coach_cannot_update(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($coach)->put(route('admin.plans.update', $plan), [
            'name' => 'Hack',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
        ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_update(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($student)->put(route('admin.plans.update', $plan), [
            'name' => 'Hack',
            'duration_days' => 30,
            'default_meeting_quota' => 4,
        ]);

        $response->assertForbidden();
    }
}
