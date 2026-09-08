<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 個人学習目標(EnrollmentGoal) の CRUD + 達成マーク / 解除の Feature テスト(HTTP 経由)。
 * ロール別可否・入力検証の境界値・達成マーク/解除・親 Enrollment 削除時の連動削除を網羅する。
 */
class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_student_can_create_goal_with_html_flow(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => '過去問 5 年分を解き終える',
            'description' => '毎週末に取り組む',
            'target_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '過去問 5 年分を解き終える',
        ]);
    }

    public function test_other_student_cannot_create_goal(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        $this->actingAs($other)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_coach_cannot_create_goal(): void
    {
        $owner = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);

        $this->actingAs($coach)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_admin_cannot_create_goal(): void
    {
        $owner = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        $this->actingAs($admin)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }

    public function test_title_max_length_boundary(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => str_repeat('あ', 100),
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertSessionDoesntHaveErrors('title');

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => str_repeat('あ', 101),
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('title');
    }

    public function test_description_max_length_boundary(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'description' => str_repeat('あ', 1000),
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertSessionDoesntHaveErrors('description');

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'description' => str_repeat('あ', 1001),
            'target_date' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('description');
    }

    public function test_target_date_required_and_past_date_rejected(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
        ])->assertSessionHasErrors('target_date');

        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'target_date' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('target_date');

        // 当日は許容(過去日のみ不可)
        $this->actingAs($student)->post(route('enrollments.goals.store', $enrollment), [
            'title' => 'タイトル',
            'target_date' => now()->toDateString(),
        ])->assertSessionDoesntHaveErrors('target_date');
    }

    public function test_owner_can_update_goal_without_changing_past_target_date(): void
    {
        // Arrange: 期日を過ぎた達成済目標(Seeder の「期日 10 日前・達成済」相当)
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'title' => '旧タイトル',
            'target_date' => now()->subDays(10)->toDateString(),
        ]);

        // Act: 期日はそのまま(触っていない)、タイトルだけ更新
        $response = $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '新タイトル',
            'target_date' => $goal->target_date->toDateString(),
        ]);

        // Assert: 過去日のままでも「期日を変更していない」ので保存できるはず
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionDoesntHaveErrors('target_date');
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id, 'title' => '新タイトル']);
    }

    public function test_owner_cannot_change_target_date_to_a_new_past_date(): void
    {
        // Arrange: 期日を過ぎた目標
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => now()->subDays(10)->toDateString(),
        ]);

        // Act: 期日を「別の」過去日へ変更しようとする
        $response = $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => 'タイトル',
            'target_date' => now()->subDays(3)->toDateString(),
        ]);

        // Assert: 新規に指定し直す過去日は新規作成時と同様に不可
        $response->assertSessionHasErrors('target_date');
    }

    public function test_owner_can_edit_and_update_goal_with_html_flow(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '旧タイトル']);

        $this->actingAs($student)->get(route('enrollment-goals.edit', $goal))->assertOk();

        $response = $this->actingAs($student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '新タイトル',
            'target_date' => now()->addMonth()->toDateString(),
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id, 'title' => '新タイトル']);
    }

    public function test_other_users_cannot_edit_or_update_goal(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        foreach ([$other, $coach, $admin] as $user) {
            $this->actingAs($user)->get(route('enrollment-goals.edit', $goal))->assertForbidden();
            $this->actingAs($user)->patch(route('enrollment-goals.update', $goal), [
                'title' => '不正更新',
                'target_date' => now()->addMonth()->toDateString(),
            ])->assertForbidden();
        }
    }

    public function test_owner_can_destroy_goal_physically(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($student)->delete(route('enrollment-goals.destroy', $goal));

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_other_users_cannot_destroy_goal(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $this->actingAs($other)->delete(route('enrollment-goals.destroy', $goal))->assertForbidden();
        $this->actingAs($admin)->delete(route('enrollment-goals.destroy', $goal))->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_owner_can_mark_and_unmark_achieved(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal));
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertNotNull($goal->fresh()->achieved_at);

        // 再度達成マークは不可(既に達成済)
        $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal))->assertForbidden();

        $response = $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal));
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertNull($goal->fresh()->achieved_at);

        // 未達成の状態で解除は不可
        $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertForbidden();
    }

    public function test_other_users_cannot_mark_or_unmark_achieved(): void
    {
        $owner = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $this->actingAs($other)->post(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
        $this->actingAs($coach)->post(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
    }

    public function test_goals_deleted_when_parent_enrollment_destroyed(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment))->assertRedirect();

        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_goal_list_orders_unachieved_before_achieved(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $achieved = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create([
            'target_date' => now()->subDays(5)->toDateString(),
        ]);
        $unachievedLater = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => now()->addMonths(2)->toDateString(),
        ]);
        $unachievedSooner = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'target_date' => now()->addDays(3)->toDateString(),
        ]);

        $ids = $enrollment->fresh()->goals->pluck('id')->all();

        $this->assertSame(
            [$unachievedSooner->id, $unachievedLater->id, $achieved->id],
            $ids,
            '未達成(期日昇順)→ 達成済の順で並ぶはず',
        );
    }

    public function test_add_goal_form_is_hidden_on_soft_deleted_enrollment(): void
    {
        // Arrange: 受講解除済み(SoftDelete 済み)の受講登録。詳細画面自体は本人が閲覧できる(withTrashed ルート)
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $enrollment->delete();

        // Act & Assert: 追加フォームが出ない(解除済み画面で押すと 404 になる導線を無くす)。
        // store ルート自体は withTrashed 未指定のため、フォームを表示しなければ 404 導線に到達しない。
        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('目標を追加');
    }

    public function test_goal_appears_on_enrollment_show_page_for_owner_coach_and_admin(): void
    {
        $owner = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $stranger = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '画面表示確認用の目標']);

        foreach ([$owner, $coach, $admin] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('enrollments.show', $enrollment))
                ->assertOk()
                ->assertSee('画面表示確認用の目標');
        }

        $this->actingAs($stranger)
            ->get(route('enrollments.show', $enrollment))
            ->assertForbidden();
    }

    private function assignCoach(User $coach, Certification $certification): void
    {
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
    }
}
