<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_meeting_pack_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.show', $pack));

        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.show');
        $response->assertSee($pack->name);
    }

    public function test_coach_cannot_view_detail(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($coach)
            ->get(route('admin.meeting-packs.show', $pack))
            ->assertForbidden();
    }

    public function test_student_cannot_view_detail(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($student)
            ->get(route('admin.meeting-packs.show', $pack))
            ->assertForbidden();
    }

    public function test_create_form_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();

        $this->actingAs($admin)->get(route('admin.meeting-packs.create'))->assertOk();
        $this->actingAs($coach)->get(route('admin.meeting-packs.create'))->assertForbidden();
    }

    public function test_edit_form_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $this->actingAs($admin)->get(route('admin.meeting-packs.edit', $pack))->assertOk();
        $this->actingAs($coach)->get(route('admin.meeting-packs.edit', $pack))->assertForbidden();
    }
}
