<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoalPolicy の判定を検証する Unit テスト。
 * 追加 / 編集 / 削除 / 達成マーク / 達成解除は受講生本人のみ可であることを網羅する。
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_allowed_only_for_enrollment_owner_student(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->create($owner, $enrollment));
        $this->assertFalse($policy->create($other, $enrollment), '他の受講生は作成不可');
        $this->assertFalse($policy->create($coach, $enrollment), 'コーチは作成不可');
        $this->assertFalse($policy->create($admin, $enrollment), '管理者は作成不可');
    }

    public function test_create_forbidden_when_enrollment_is_soft_deleted(): void
    {
        // Arrange: 解除済み(SoftDelete 済み)の受講登録。本人でも追加不可(解除済み画面での 404 防止)
        $owner = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $enrollment->delete();
        $policy = new EnrollmentGoalPolicy;

        $this->assertFalse($policy->create($owner->fresh(), $enrollment->fresh()), '解除済み受講登録では本人でも追加不可のはず');
    }

    public function test_update_and_delete_allowed_only_for_owner(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->update($owner, $goal));
        $this->assertTrue($policy->delete($owner, $goal));
        $this->assertFalse($policy->update($other, $goal), '他人の目標は編集不可');
        $this->assertFalse($policy->delete($admin, $goal), '管理者も削除不可(閲覧専用)');
    }

    public function test_mark_achieved_requires_not_already_achieved(): void
    {
        $owner = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $unachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->markAchieved($owner, $unachieved));
        $this->assertFalse($policy->markAchieved($owner, $achieved), '既に達成済の場合は再マーク不可');
    }

    public function test_unmark_achieved_requires_currently_achieved(): void
    {
        $owner = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $unachieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $policy = new EnrollmentGoalPolicy;

        $this->assertTrue($policy->unmarkAchieved($owner, $achieved));
        $this->assertFalse($policy->unmarkAchieved($owner, $unachieved), '未達成の場合は解除不可');
    }
}
