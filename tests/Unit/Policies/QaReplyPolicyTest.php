<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QaReplyPolicy の単体テスト。受講生 / コーチ / 管理者 × 投稿・編集・削除の組み合わせを網羅する
 * (レビュー指摘 4)。update / delete がスレッド側と同じ可視性チェックを併用することも検証する
 * (レビュー指摘 3: 資格が公開停止になった後やコーチが担当を外された後は、自分の回答であっても
 * 編集・削除できないこと)。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QaReplyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new QaReplyPolicy;
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $admin = User::factory()->admin()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
    }

    // --- create ---

    public function test_student_can_create_reply_on_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertTrue($this->policy->create($student, $thread));
    }

    public function test_student_cannot_create_reply_on_unpublished_certification_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->draft())->create();

        $this->assertFalse($this->policy->create($student, $thread));
    }

    public function test_assigned_coach_can_create_reply_but_unassigned_coach_cannot(): void
    {
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $assignedCoach = User::factory()->coach()->create();
        $this->assignCoach($certification, $assignedCoach);
        $unassignedCoach = User::factory()->coach()->create();

        $this->assertTrue($this->policy->create($assignedCoach, $thread));
        $this->assertFalse($this->policy->create($unassignedCoach, $thread));
    }

    public function test_admin_cannot_create_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();

        $this->assertFalse($this->policy->create($admin, $thread));
    }

    // --- update ---

    public function test_owner_can_update_reply_on_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);

        $this->assertTrue($this->policy->update($student, $reply));
    }

    public function test_owner_cannot_update_reply_after_certification_becomes_unpublished(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);

        $certification->update(['status' => CertificationStatus::Draft]);

        $this->assertFalse($this->policy->update($student, $reply->fresh()));
    }

    public function test_owner_coach_cannot_update_reply_after_being_unassigned(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);
        $thread = QaThread::factory()->for($certification)->create();
        $reply = $thread->replies()->create(['user_id' => $coach->id, 'body' => '回答']);

        $certification->coaches()->detach($coach->id);

        $this->assertFalse($this->policy->update($coach, $reply->fresh()));
    }

    public function test_non_owner_cannot_update_reply(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $this->assertFalse($this->policy->update($student, $reply));
    }

    // --- delete ---

    public function test_owner_can_delete_reply_on_visible_thread(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);

        $this->assertTrue($this->policy->delete($student, $reply));
    }

    public function test_owner_cannot_delete_reply_after_certification_becomes_unpublished(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->for($certification)->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);

        $certification->update(['status' => CertificationStatus::Draft]);

        $this->assertFalse($this->policy->delete($student, $reply->fresh()));
    }

    public function test_owner_coach_cannot_delete_reply_after_being_unassigned(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);
        $thread = QaThread::factory()->for($certification)->create();
        $reply = $thread->replies()->create(['user_id' => $coach->id, 'body' => '回答']);

        $certification->coaches()->detach($coach->id);

        $this->assertFalse($this->policy->delete($coach, $reply->fresh()));
    }

    public function test_non_owner_cannot_delete_reply(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $this->assertFalse($this->policy->delete($student, $reply));
    }

    public function test_admin_can_delete_reply_regardless_of_certification_status(): void
    {
        $admin = User::factory()->admin()->create();
        $publishedThread = QaThread::factory()->for(Certification::factory()->published())->create();
        $publishedReply = $publishedThread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);
        $draftThread = QaThread::factory()->for(Certification::factory()->draft())->create();
        $draftReply = $draftThread->replies()->create(['user_id' => User::factory()->student()->create()->id, 'body' => '回答']);

        $this->assertTrue($this->policy->delete($admin, $publishedReply));
        $this->assertTrue($this->policy->delete($admin, $draftReply));
    }

    // --- moderateDelete ---

    public function test_only_admin_can_moderate_delete_reply(): void
    {
        $student = User::factory()->student()->create();
        $thread = QaThread::factory()->for(Certification::factory()->published())->create();
        $reply = $thread->replies()->create(['user_id' => $student->id, 'body' => '回答']);
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse($this->policy->moderateDelete($student, $reply));
        $this->assertFalse($this->policy->moderateDelete($coach, $reply));
        $this->assertTrue($this->policy->moderateDelete($admin, $reply));
    }
}
