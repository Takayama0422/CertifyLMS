<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_meeting_pack_list(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        $response->assertOk();
        $response->assertViewIs('meeting-pack.management.index');
        $response->assertViewHas('plans');
    }

    public function test_coach_cannot_access_index(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('admin.meeting-packs.index'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.meeting-packs.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->published()->create(['name' => '5 回パック']);
        MeetingPack::factory()->published()->create(['name' => '10 回パック']);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', ['keyword' => '5 回']));

        $response->assertOk();
        $response->assertSee('5 回パック');
        $response->assertDontSee('10 回パック');
    }

    public function test_keyword_filter_does_not_match_description(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->published()->create([
            'name' => '5 回パック',
            'description' => 'これは限定キャンペーン向けの説明文です',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', ['keyword' => '限定キャンペーン']));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(0, $plans->total());
    }

    #[DataProvider('statusFilterCases')]
    public function test_status_filter_returns_only_specified_status(string $status, string $expectedName, array $hiddenNames): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->draft()->create(['name' => 'Draft Pack']);
        MeetingPack::factory()->published()->create(['name' => 'Published Pack']);
        MeetingPack::factory()->archived()->create(['name' => 'Archived Pack']);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', ['status' => $status]));

        $response->assertOk();
        $response->assertSee($expectedName);
        foreach ($hiddenNames as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    /**
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function statusFilterCases(): array
    {
        return [
            '下書きを指定すると下書きのみ' => ['draft', 'Draft Pack', ['Published Pack', 'Archived Pack']],
            '公開中を指定すると公開中のみ' => ['published', 'Published Pack', ['Draft Pack', 'Archived Pack']],
            'アーカイブを指定するとアーカイブのみ' => ['archived', 'Archived Pack', ['Draft Pack', 'Published Pack']],
        ];
    }

    public function test_list_prioritizes_published_over_draft_and_archived(): void
    {
        $admin = User::factory()->admin()->create();
        // 作成順はアーカイブ→下書き→公開中。並び順が公開中優先であれば先頭は公開中になる。
        MeetingPack::factory()->archived()->create(['name' => 'Archived Pack', 'sort_order' => 1]);
        MeetingPack::factory()->draft()->create(['name' => 'Draft Pack', 'sort_order' => 1]);
        MeetingPack::factory()->published()->create(['name' => 'Published Pack', 'sort_order' => 1]);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(
            ['Published Pack', 'Draft Pack', 'Archived Pack'],
            $plans->pluck('name')->all(),
        );
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        MeetingPack::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index'));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(20, $plans->perPage());
        $this->assertSame(22, $plans->total());
    }
}
