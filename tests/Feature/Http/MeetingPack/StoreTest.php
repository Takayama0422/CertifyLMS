<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

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
            'name' => '5 回パック',
            'description' => '説明文',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => 'price_test123',
            'sort_order' => 1,
        ], $override);
    }

    public function test_admin_can_create_meeting_pack_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_packs', [
            'name' => '5 回パック',
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_coach_cannot_create_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertForbidden();
    }

    public function test_student_cannot_create_meeting_pack(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertForbidden();
    }

    public function test_name_is_required(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_name_max_length_is_100(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['name' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['name' => str_repeat('a', 100)]))
            ->assertSessionDoesntHaveErrors('name');
    }

    public function test_description_max_length_is_2000(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload(['description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('description');
    }

    #[DataProvider('meetingCountBoundaryCases')]
    public function test_meeting_count_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), $this->payload(['meeting_count' => $value]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('meeting_count');
        } else {
            $response->assertSessionHasErrors('meeting_count');
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function meetingCountBoundaryCases(): array
    {
        return [
            '0 は不可' => [0, false],
            '1 は可' => [1, true],
            '100 は可' => [100, true],
            '101 は不可' => [101, false],
        ];
    }

    #[DataProvider('priceBoundaryCases')]
    public function test_price_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), $this->payload(['price' => $value]));

        if ($valid) {
            $response->assertSessionDoesntHaveErrors('price');
        } else {
            $response->assertSessionHasErrors('price');
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function priceBoundaryCases(): array
    {
        return [
            '-1 は不可' => [-1, false],
            '0 は可' => [0, true],
            '1000000 は可' => [1000000, true],
            '1000001 は不可' => [1000001, false],
        ];
    }

    public function test_meeting_count_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        unset($payload['meeting_count']);

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload)
            ->assertSessionHasErrors('meeting_count');
    }

    public function test_price_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        unset($payload['price']);

        $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $payload)
            ->assertSessionHasErrors('price');
    }
}
