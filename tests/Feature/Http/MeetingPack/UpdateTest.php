<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

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
