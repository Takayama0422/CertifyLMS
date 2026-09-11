<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingPackPolicy の判定を検証する Unit テスト。
 * 全 ability を admin のみ許可し、coach / student は全不可であることを網羅する。
 */
class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_perform_all_abilities(): void
    {
        $admin = User::factory()->admin()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->view($admin, $pack));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $pack));
        $this->assertTrue($policy->delete($admin, $pack));
        $this->assertTrue($policy->publish($admin, $pack));
        $this->assertTrue($policy->archive($admin, $pack));
        $this->assertTrue($policy->unarchive($admin, $pack));
    }

    public function test_coach_cannot_perform_any_ability(): void
    {
        $coach = User::factory()->coach()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertFalse($policy->viewAny($coach));
        $this->assertFalse($policy->view($coach, $pack));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->update($coach, $pack));
        $this->assertFalse($policy->delete($coach, $pack));
        $this->assertFalse($policy->publish($coach, $pack));
        $this->assertFalse($policy->archive($coach, $pack));
        $this->assertFalse($policy->unarchive($coach, $pack));
    }

    public function test_student_cannot_perform_any_ability(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertFalse($policy->viewAny($student));
        $this->assertFalse($policy->view($student, $pack));
        $this->assertFalse($policy->create($student));
        $this->assertFalse($policy->update($student, $pack));
        $this->assertFalse($policy->delete($student, $pack));
        $this->assertFalse($policy->publish($student, $pack));
        $this->assertFalse($policy->archive($student, $pack));
        $this->assertFalse($policy->unarchive($student, $pack));
    }
}
