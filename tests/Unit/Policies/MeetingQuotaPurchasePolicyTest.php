<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\MeetingQuotaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingQuotaPolicy::purchase(S-A-03 追加面談購入)の判定を検証する Unit テスト。
 * 学習中(in_progress)の受講生のみ許可し、ロール違い・状態違いを網羅する。
 *
 * 既存の MeetingQuotaPolicyTest(viewHistory 用)は書き換えず、新規 ability 用として別ファイルに追加する。
 */
class MeetingQuotaPurchasePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_student_can_purchase(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertTrue($policy->purchase($student));
    }

    public function test_graduated_student_cannot_purchase(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertFalse($policy->purchase($student));
    }

    public function test_withdrawn_student_cannot_purchase(): void
    {
        $student = User::factory()->student()->withdrawn()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertFalse($policy->purchase($student));
    }

    public function test_invited_student_cannot_purchase(): void
    {
        $student = User::factory()->student()->invited()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertFalse($policy->purchase($student));
    }

    public function test_in_progress_coach_cannot_purchase(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertFalse($policy->purchase($coach));
    }

    public function test_in_progress_admin_cannot_purchase(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $policy = new MeetingQuotaPolicy;

        $this->assertFalse($policy->purchase($admin));
    }
}
