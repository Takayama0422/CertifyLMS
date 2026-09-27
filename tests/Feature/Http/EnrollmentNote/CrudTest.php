<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コーチメモ(EnrollmentNote) の CRUD の Feature テスト(HTTP 経由)。
 * ロール別可否(受講生は区画自体が出ない/全拒否)・入力検証の境界値・他コーチのメモ操作拒否・
 * 管理者の越境操作・親 Enrollment 削除時の一覧除外を網羅する。
 */
class CrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_coach_can_create_note_with_html_flow(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);

        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '最近 chat の応答が遅れています。',
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'user_id' => $coach->id,
            'body' => '最近 chat の応答が遅れています。',
        ]);
    }

    public function test_admin_can_create_note_on_any_enrollment(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($admin)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '運営観察メモ',
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', ['enrollment_id' => $enrollment->id, 'user_id' => $admin->id]);
    }

    public function test_unassigned_coach_cannot_create_note(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '担当外です',
        ])->assertForbidden();
    }

    public function test_student_cannot_create_note(): void
    {
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $this->actingAs($student)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '受講生からの追加',
        ])->assertForbidden();
    }

    public function test_body_required_and_max_length_boundary(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '',
        ])->assertSessionHasErrors('body');

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => str_repeat('あ', 2000),
        ])->assertSessionDoesntHaveErrors('body');

        $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => str_repeat('あ', 2001),
        ])->assertSessionHasErrors('body');
    }

    public function test_author_can_edit_and_update_own_note_with_html_flow(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($coach)->create();

        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertOk();

        $response = $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), [
            'body' => '更新後の本文',
        ]);

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '更新後の本文']);
    }

    public function test_other_coach_cannot_edit_or_update_note_but_can_view(): void
    {
        $author = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $this->assignCoach($author, $enrollment->certification);
        $this->assignCoach($otherCoach, $enrollment->certification);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($author)->create(['body' => '他コーチのメモ']);

        $this->actingAs($otherCoach)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($otherCoach)->patch(route('enrollment-notes.update', $note), [
            'body' => '不正更新',
        ])->assertForbidden();

        // 閲覧(受講登録詳細画面での一覧表示)は可能
        $this->actingAs($otherCoach)->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertSee('他コーチのメモ');
    }

    public function test_admin_can_edit_and_delete_any_note(): void
    {
        $author = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($author)->create();

        $this->actingAs($admin)->patch(route('enrollment-notes.update', $note), [
            'body' => '管理者による是正',
        ])->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id, 'body' => '管理者による是正']);

        $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $note))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_author_can_destroy_own_note_physically(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($coach)->create();

        $response = $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note));

        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_other_coach_cannot_destroy_note(): void
    {
        $author = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($author)->create();

        $this->actingAs($otherCoach)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public function test_student_section_is_absent_from_enrollment_show_page(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($coach)->create(['body' => '受講生には見えないはずのメモ本文']);

        $this->actingAs($student)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('受講生には見えないはずのメモ本文')
            ->assertDontSee('コーチメモ');
    }

    public function test_notes_excluded_from_list_when_parent_enrollment_destroyed(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($coach)->create(['body' => '解除前のメモ本文']);

        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment))->assertRedirect();
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);

        $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment))
            ->assertOk()
            ->assertDontSee('解除前のメモ本文');
    }

    public function test_student_cannot_edit_update_or_destroy_note(): void
    {
        // Arrange: ロールグループ指定(role:coach|admin 等)が落ちた場合に検知できるよう、
        // 受講生による編集/更新/削除の各経路への到達を個別に検証する
        $student = User::factory()->student()->create();
        $author = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $this->assignCoach($author, $enrollment->certification);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($author)->create();

        $this->actingAs($student)->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->actingAs($student)->patch(route('enrollment-notes.update', $note), [
            'body' => '受講生による不正更新',
        ])->assertForbidden();
        $this->actingAs($student)->delete(route('enrollment-notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public function test_notes_remain_in_database_when_parent_enrollment_soft_deleted(): void
    {
        // Arrange: 親 Enrollment を SoftDelete する前にメモを作成
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $this->assignCoach($coach, $enrollment->certification);
        $note = EnrollmentNote::factory()->forEnrollment($enrollment)->forAuthor($coach)->create();

        // Act
        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment))->assertRedirect();
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);

        // Assert: 画面から除外されるだけで、メモ行自体は物理削除されず DB に残る(業務記録は保持する仕様)
        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    private function assignCoach(User $coach, Certification $certification): void
    {
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
    }
}
