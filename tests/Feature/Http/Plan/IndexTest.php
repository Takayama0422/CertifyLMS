<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan_list(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        $response->assertOk();
        $response->assertViewIs('plan.management.index');
        $response->assertViewHas('plans');
    }

    public function test_list_prioritizes_published_over_draft_and_archived(): void
    {
        $admin = User::factory()->admin()->create();
        // 作成順はアーカイブ→下書き→公開中。並び順が公開中優先であれば先頭は公開中になる。
        Plan::factory()->archived()->create(['name' => 'Archived Plan', 'sort_order' => 1]);
        Plan::factory()->draft()->create(['name' => 'Draft Plan', 'sort_order' => 1]);
        Plan::factory()->published()->create(['name' => 'Published Plan', 'sort_order' => 1]);

        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(
            ['Published Plan', 'Draft Plan', 'Archived Plan'],
            $plans->pluck('name')->all(),
        );
    }

    public function test_coach_cannot_access_index(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)
            ->get(route('admin.plans.index'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.plans.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->create(['name' => 'ベーシックプラン']);
        Plan::factory()->published()->create(['name' => 'プレミアムプラン']);

        $response = $this->actingAs($admin)->get(route('admin.plans.index', ['keyword' => 'ベーシック']));

        $response->assertOk();
        $response->assertSee('ベーシックプラン');
        $response->assertDontSee('プレミアムプラン');
    }

    public function test_keyword_filter_does_not_match_description(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->create([
            'name' => 'ベーシックプラン',
            'description' => 'これは限定キャンペーン向けの説明文です',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.plans.index', ['keyword' => '限定キャンペーン']));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(0, $plans->total());
    }

    #[DataProvider('statusFilterCases')]
    public function test_status_filter_returns_only_specified_status(string $status, string $expectedName, array $hiddenNames): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->draft()->create(['name' => 'Draft Plan']);
        Plan::factory()->published()->create(['name' => 'Published Plan']);
        Plan::factory()->archived()->create(['name' => 'Archived Plan']);

        $response = $this->actingAs($admin)->get(route('admin.plans.index', ['status' => $status]));

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
            '下書きを指定すると下書きのみ' => ['draft', 'Draft Plan', ['Published Plan', 'Archived Plan']],
            '公開中を指定すると公開中のみ' => ['published', 'Published Plan', ['Draft Plan', 'Archived Plan']],
            'アーカイブを指定するとアーカイブのみ' => ['archived', 'Archived Plan', ['Draft Plan', 'Published Plan']],
        ];
    }

    public function test_row_shows_linked_user_count(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create(['name' => '受講者ありプラン']);
        User::factory()->student()->count(2)->create(['plan_id' => $plan->id]);
        Plan::factory()->published()->create(['name' => '受講者なしプラン']);

        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $found = $plans->firstWhere('id', $plan->id);
        $this->assertSame(2, $found->users_count);
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        Plan::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(route('admin.plans.index'));

        $response->assertOk();
        $plans = $response->viewData('plans');
        $this->assertSame(20, $plans->perPage());
        $this->assertSame(22, $plans->total());
    }
}
