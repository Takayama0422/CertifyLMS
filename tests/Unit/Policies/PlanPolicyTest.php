<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanPolicy の判定を検証する Unit テスト。
 * 全 ability を admin のみ許可し、coach / student は全不可であることを網羅する。
 */
class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_perform_all_abilities(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        $policy = new PlanPolicy;

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->view($admin, $plan));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $plan));
        $this->assertTrue($policy->delete($admin, $plan));
        $this->assertTrue($policy->publish($admin, $plan));
        $this->assertTrue($policy->archive($admin, $plan));
        $this->assertTrue($policy->unarchive($admin, $plan));
    }

    public function test_coach_cannot_perform_any_ability(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();
        $policy = new PlanPolicy;

        $this->assertFalse($policy->viewAny($coach));
        $this->assertFalse($policy->view($coach, $plan));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->update($coach, $plan));
        $this->assertFalse($policy->delete($coach, $plan));
        $this->assertFalse($policy->publish($coach, $plan));
        $this->assertFalse($policy->archive($coach, $plan));
        $this->assertFalse($policy->unarchive($coach, $plan));
    }

    public function test_student_cannot_perform_any_ability(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->draft()->create();
        $policy = new PlanPolicy;

        $this->assertFalse($policy->viewAny($student));
        $this->assertFalse($policy->view($student, $plan));
        $this->assertFalse($policy->create($student));
        $this->assertFalse($policy->update($student, $plan));
        $this->assertFalse($policy->delete($student, $plan));
        $this->assertFalse($policy->publish($student, $plan));
        $this->assertFalse($policy->archive($student, $plan));
        $this->assertFalse($policy->unarchive($student, $plan));
    }
}
