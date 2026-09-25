<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
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
            'name' => '5 回パック',
            'description' => '説明文',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => 'price_test123',
            'sort_order' => 1,
        ], $override);
    }

    public function test_admin_can_update_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create(['name' => 'Old Name']);

        $payload = [
            'name' => 'New Name',
            'description' => '更新後の説明',
            'meeting_count' => 10,
            'price' => 30000,
            'stripe_price_id' => 'price_new',
            'sort_order' => 5,
        ];

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $payload);

        $response->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertDatabaseHas('meeting_packs', [
            'id' => $pack->id,
            'name' => 'New Name',
            'meeting_count' => 10,
            'price' => 30000,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_is_unchanged_even_if_payload_includes_status(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $payload = [
            'name' => $pack->name,
            'meeting_count' => $pack->meeting_count,
            'price' => $pack->price,
            'status' => 'draft',
        ];

        $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $payload);

        $this->assertSame('published', $pack->fresh()->status->value);
    }

    public function test_name_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_name_max_length_is_100(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['name' => str_repeat('a', 101)]))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['name' => str_repeat('a', 100)]))
            ->assertSessionDoesntHaveErrors('name');
    }

    public function test_description_max_length_is_2000(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['description' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('description');
    }

    #[DataProvider('meetingCountBoundaryCases')]
    public function test_meeting_count_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $this->payload(['meeting_count' => $value]));

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
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $this->payload(['price' => $value]));

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

    #[DataProvider('sortOrderBoundaryCases')]
    public function test_sort_order_boundaries(int $value, bool $valid): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $this->payload(['sort_order' => $value]));

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

    public function test_stripe_price_id_max_length_is_255(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['stripe_price_id' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('stripe_price_id');

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $this->payload(['stripe_price_id' => str_repeat('a', 255)]))
            ->assertSessionDoesntHaveErrors('stripe_price_id');
    }

    public function test_meeting_count_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $payload = $this->payload();
        unset($payload['meeting_count']);

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $payload)
            ->assertSessionHasErrors('meeting_count');
    }

    public function test_price_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $payload = $this->payload();
        unset($payload['price']);

        $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $pack), $payload)
            ->assertSessionHasErrors('price');
    }

    public function test_sort_order_is_kept_when_omitted(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create(['sort_order' => 7]);
        $payload = $this->payload();
        unset($payload['sort_order']);

        $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $pack), $payload);

        $this->assertSame(7, $pack->fresh()->sort_order);
    }

    public function test_coach_cannot_update(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($coach)->patch(route('admin.meeting-packs.update', $pack), [
            'name' => 'Hack',
            'meeting_count' => 1,
            'price' => 1000,
        ]);

        $response->assertForbidden();
    }

    public function test_student_cannot_update(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($student)->patch(route('admin.meeting-packs.update', $pack), [
            'name' => 'Hack',
            'meeting_count' => 1,
            'price' => 1000,
        ]);

        $response->assertForbidden();
    }
}
