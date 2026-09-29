<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 管理者お知らせ配信(`/admin/announcements/*`)の検証。
 * 観点: admin 限定(index/create/store/show) / 入力検証 / 正常系の遷移先とフラッシュ文言 /
 * 不可逆性(編集・削除・再配信の経路が存在しないこと)。
 */
class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_index(): void
    {
        $admin = User::factory()->admin()->create();
        Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        $response->assertOk();
    }

    public function test_student_cannot_view_index(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('admin.announcements.index'));

        $response->assertForbidden();
    }

    public function test_coach_cannot_view_index(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('admin.announcements.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_create_form(): void
    {
        $admin = User::factory()->admin()->create();
        Certification::factory()->published()->create();

        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        $response->assertOk();
        $response->assertViewHas('certifications');
        $response->assertViewHas('students');
    }

    public function test_student_cannot_view_create_form(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('admin.announcements.create'));

        $response->assertForbidden();
    }

    public function test_store_validation_requires_title_body_and_target_type(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), []);

        $response->assertSessionHasErrors(['title', 'body', 'target_type']);
    }

    public function test_store_rejects_title_over_200_chars(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => str_repeat('あ', 201),
            'body' => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_store_rejects_body_over_5000_chars(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => str_repeat('あ', 5001),
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $response->assertSessionHasErrors('body');
    }

    public function test_store_requires_certification_when_target_type_is_certification(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::Certification->value,
        ]);

        $response->assertSessionHasErrors('target_certification_id');
    }

    public function test_store_requires_user_when_target_type_is_user(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::User->value,
        ]);

        $response->assertSessionHasErrors('target_user_id');
    }

    public function test_store_rejects_coach_id_as_target_user(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $coach->id,
        ]);

        $response->assertSessionHasErrors('target_user_id');
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_store_rejects_admin_id_as_target_user(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $otherAdmin->id,
        ]);

        $response->assertSessionHasErrors('target_user_id');
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_store_dispatches_and_redirects_to_show_with_flash(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '重要なお知らせ',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $announcement = Announcement::query()->where('title', '重要なお知らせ')->firstOrFail();
        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $response->assertSessionHas('success', 'お知らせを配信しました。');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $student->id]);
    }

    public function test_recipient_can_reach_full_announcement_body_via_notification_detail_link(): void
    {
        // 配信(実 Action)→ 通知の data.url → その URL への遷移 → 本文全文表示、を通しで検証する。
        // AdminAnnouncementNotification::url() は自身の DatabaseNotification ID(送信直前に採番)に
        // 依存するため、自作の通知データではなく実際の配信結果でこの経路を確認する必要がある。
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '通知詳細への到達確認',
            'body' => "本文全文を確認するためのお知らせです。\n複数行を含みます。",
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $student->id)
            ->where('type', AdminAnnouncementNotification::class)
            ->firstOrFail();

        $this->assertSame(route('notifications.show', $notification->id), $notification->data['url']);

        $response = $this->actingAs($student)->get($notification->data['url']);

        $response->assertOk();
        $response->assertSee('通知詳細への到達確認');
        $response->assertSee('本文全文を確認するためのお知らせです。', false);
    }

    public function test_student_cannot_store(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_admin_can_view_show(): void
    {
        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.announcements.show', $announcement));

        $response->assertOk();
        $response->assertSee($announcement->title);
    }

    public function test_student_cannot_view_show(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $response = $this->actingAs($student)->get(route('admin.announcements.show', $announcement));

        $response->assertForbidden();
    }

    public function test_no_edit_or_delete_or_redispatch_routes_exist(): void
    {
        $this->assertFalse(Route::has('admin.announcements.edit'));
        $this->assertFalse(Route::has('admin.announcements.update'));
        $this->assertFalse(Route::has('admin.announcements.destroy'));

        $admin = User::factory()->admin()->create();
        $announcement = Announcement::factory()->create(['created_by_user_id' => $admin->id]);

        $patchResponse = $this->actingAs($admin)->patch(route('admin.announcements.show', $announcement), ['title' => '改変']);
        $patchResponse->assertStatus(405);

        $deleteResponse = $this->actingAs($admin)->delete(route('admin.announcements.show', $announcement));
        $deleteResponse->assertStatus(405);
    }
}
