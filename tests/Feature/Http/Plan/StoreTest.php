<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreTest extends TestCase
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

    public function test_admin_can_create_plan_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('plans', [
            'name' => '1 ヶ月プラン 4 回',
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_coach_cannot_create_plan(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(route('admin.plans.store'), $this->payload());

        $response->assertForbidden();
    }

    public function test_student_cannot_create_plan(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('admin.plans.store'), $this->payload());

        $response->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_name_max_length_is_100(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['name' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['name' => str_repeat('a', 100)]))
            ->assertSessionDoesntHaveErrors('name');
    }

    public function test_description_max_length_is_2000(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('description');
    }

    #[DataProvider('durationDaysBoundaryCases')]
    public function test_duration_days_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload(['duration_days' => $value]));

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

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $this->payload(['default_meeting_quota' => $value]));

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

    public function test_duration_days_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        unset($payload['duration_days']);

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $payload)
            ->assertSessionHasErrors('duration_days');
    }

    public function test_default_meeting_quota_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        unset($payload['default_meeting_quota']);

        $this->actingAs($admin)
            ->post(route('admin.plans.store'), $payload)
            ->assertSessionHasErrors('default_meeting_quota');
    }
}
