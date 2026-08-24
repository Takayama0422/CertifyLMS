<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateFormAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コードレビュー指摘 4(T-A-02)の回帰テスト。
 * `MeetingController::create()` が持っていたデータ取得(certification の Eager Loading /
 * 残面談回数の取得)を Action へ切り出したことを検証する。
 */
class CreateFormActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_enrollment_with_certification_loaded_and_meetings_remaining(): void
    {
        $student = User::factory()->student()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $result = app(CreateFormAction::class)($enrollment, $student);

        $this->assertTrue($result['enrollment']->relationLoaded('certification'));
        $this->assertSame($enrollment->id, $result['enrollment']->id);
        $this->assertIsInt($result['meetingsRemaining']);
    }
}
