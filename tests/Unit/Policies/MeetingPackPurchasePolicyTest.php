<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingPackPolicy::purchase(S-A-03 追加面談購入の対象妥当性)の判定を検証する Unit テスト。
 * 公開中(published)の面談パックのみ許可し、下書き / アーカイブは不可であることを網羅する。
 *
 * 既存の MeetingPackPolicyTest(admin 専用操作群)は書き換えず、新規 ability 用として別ファイルに追加する。
 */
class MeetingPackPurchasePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_pack_can_be_purchased(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $policy = new MeetingPackPolicy;

        // purchase の第一引数(User)は判定に使わないため、テスト対象の users テーブル依存を避け nullable にはできないが、
        // Policy 実装が role/status を見ないことをコードで確認しているため任意の student で十分。
        $student = User::factory()->student()->inProgress()->create();

        $this->assertTrue($policy->purchase($student, $pack));
    }

    public function test_draft_pack_cannot_be_purchased(): void
    {
        $pack = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;
        $student = User::factory()->student()->inProgress()->create();

        $this->assertFalse($policy->purchase($student, $pack));
    }

    public function test_archived_pack_cannot_be_purchased(): void
    {
        $pack = MeetingPack::factory()->archived()->create();
        $policy = new MeetingPackPolicy;
        $student = User::factory()->student()->inProgress()->create();

        $this->assertFalse($policy->purchase($student, $pack));
    }
}
