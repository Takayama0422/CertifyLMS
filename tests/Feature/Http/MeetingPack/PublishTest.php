<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_publishes_draft_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.publish', $pack));

        $response->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertSame('published', $pack->fresh()->status->value);
    }

    public function test_cannot_publish_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.meeting-packs.publish', $pack));

        $response->assertStatus(409);
    }

    public function test_cannot_publish_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.meeting-packs.publish', $pack));

        $response->assertStatus(409);
    }

    public function test_coach_cannot_publish(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($coach)->post(route('admin.meeting-packs.publish', $pack));

        $response->assertForbidden();
    }
}
