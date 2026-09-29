<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * お知らせ配信の認可(要件シート S4: 全操作 admin のみ)を検証する。
 */
class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_any_create_and_view(): void
    {
        $policy = new AnnouncementPolicy;
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->view($admin, $announcement));
    }

    public function test_student_cannot_view_any_create_or_view(): void
    {
        $policy = new AnnouncementPolicy;
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $this->assertFalse($policy->viewAny($student));
        $this->assertFalse($policy->create($student));
        $this->assertFalse($policy->view($student, $announcement));
    }

    public function test_coach_cannot_view_any_create_or_view(): void
    {
        $policy = new AnnouncementPolicy;
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $this->assertFalse($policy->viewAny($coach));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->view($coach, $announcement));
    }
}
