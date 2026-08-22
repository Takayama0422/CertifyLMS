<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certification;

use App\Models\Certification;
use App\Models\CertificationCategory;
use App\Models\CertificationCoachAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_certification_list(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertViewIs('certification.management.index');
        $response->assertViewHas('certifications');
    }

    public function test_coach_sees_only_assigned_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $assigned = Certification::factory()->published()->create(['name' => 'My Assigned Cert']);
        $other = Certification::factory()->published()->create(['name' => 'Unassigned Cert']);

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($coach)->get(route('admin.certifications.index'));

        $response->assertOk();
        $response->assertSee('My Assigned Cert');
        $response->assertDontSee('Unassigned Cert');
    }

    public function test_student_cannot_access_admin_certifications_index(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('admin.certifications.index'))
            ->assertForbidden();
    }

    public function test_keyword_filter_matches_name_only(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->create(['name' => 'TOEIC Listening']);
        Certification::factory()->published()->create(['name' => 'PMP Certification']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['keyword' => 'TOEIC']));

        $response->assertOk();
        $response->assertSee('TOEIC Listening');
        $response->assertDontSee('PMP Certification');
    }

    public function test_status_filter_returns_only_matching_status(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->draft()->create(['name' => 'Draft One']);
        Certification::factory()->published()->create(['name' => 'Published One']);
        Certification::factory()->archived()->create(['name' => 'Archived One']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => 'published']));

        $response->assertOk();
        $response->assertSee('Published One');
        $response->assertDontSee('Draft One');
        $response->assertDontSee('Archived One');
    }

    /**
     * 状態フィルタで指定した状態の資格だけが表示され、他状態は表示されないこと。
     *
     * published は test_status_filter_returns_only_matching_status で検証済みのため、
     * ここでは残る draft / archived を網羅する。
     */
    #[DataProvider('statusFilterCases')]
    public function test_status_filter_returns_only_specified_status(string $status, string $expectedName, array $hiddenNames): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        Certification::factory()->draft()->create(['name' => 'Draft One']);
        Certification::factory()->published()->create(['name' => 'Published One']);
        Certification::factory()->archived()->create(['name' => 'Archived One']);

        // Act
        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['status' => $status]));

        // Assert
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
            '下書きを指定すると下書きのみ' => ['draft', 'Draft One', ['Published One', 'Archived One']],
            'アーカイブを指定するとアーカイブのみ' => ['archived', 'Archived One', ['Draft One', 'Published One']],
        ];
    }

    public function test_category_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $category = CertificationCategory::factory()->create(['name' => 'Tech Cert']);
        Certification::factory()->published()->create(['name' => 'Match', 'category_id' => $category->id]);
        Certification::factory()->published()->create(['name' => 'OtherCat']);

        $response = $this->actingAs($admin)->get(route('admin.certifications.index', ['category_id' => $category->id]));

        $response->assertOk();
        $response->assertSee('Match');
        $response->assertDontSee('OtherCat');
    }

    public function test_paginates_20_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->count(22)->create();

        $response = $this->actingAs($admin)->get(route('admin.certifications.index'));

        $response->assertOk();
        $certs = $response->viewData('certifications');
        $this->assertSame(20, $certs->perPage());
        $this->assertSame(22, $certs->total());
    }
}
