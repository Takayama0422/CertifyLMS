<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_archives_published_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.archive', $pack));

        $response->assertRedirect(route('admin.meeting-packs.show', $pack));
        $this->assertSame('archived', $pack->fresh()->status->value);
    }

    public function test_cannot_archive_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.meeting-packs.archive', $pack));

        $response->assertStatus(409);
    }

    public function test_cannot_archive_already_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.meeting-packs.archive', $pack));

        $response->assertStatus(409);
    }

    public function test_coach_cannot_archive(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($coach)->post(route('admin.meeting-packs.archive', $pack));

        $response->assertForbidden();
    }
}
