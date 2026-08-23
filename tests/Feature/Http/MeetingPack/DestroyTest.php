<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_draft_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $pack));

        $response->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $pack->id]);
    }

    public function test_admin_can_delete_archived_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)->delete(route('admin.meeting-packs.destroy', $pack));

        $response->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $pack->id]);
    }

    public function test_cannot_delete_published_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->deleteJson(route('admin.meeting-packs.destroy', $pack));

        $response->assertStatus(409);
        $this->assertDatabaseHas('meeting_packs', ['id' => $pack->id]);
    }

    public function test_coach_cannot_delete(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($coach)->delete(route('admin.meeting-packs.destroy', $pack));

        $response->assertForbidden();
    }

    public function test_student_cannot_delete(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($student)->delete(route('admin.meeting-packs.destroy', $pack));

        $response->assertForbidden();
    }
}
