<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /qa-board/create` の閲覧可否を検証する。スレッド投稿は受講生のみ。
 */
class CreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_create_form(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->get(route('qa-board.create'));

        $response->assertOk();
        $response->assertViewIs('qa-thread.create');
    }

    public function test_coach_is_forbidden(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->get(route('qa-board.create'));

        $response->assertForbidden();
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('qa-board.create'));

        $response->assertForbidden();
    }
}
