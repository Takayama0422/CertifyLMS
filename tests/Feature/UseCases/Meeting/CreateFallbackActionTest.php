<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\UseCases\Meeting\CreateFallbackAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コードレビュー指摘 4(T-A-02)の回帰テスト。
 * `MeetingController::createFallback()` が持っていたデータ取得(status 絞り込み +
 * certification の Eager Loading + null 分岐)を Action へ切り出したことを検証する。
 */
class CreateFallbackActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_learning_and_passed_enrollments_with_certification_loaded(): void
    {
        $student = User::factory()->student()->create();
        $learning = Enrollment::factory()->for($student)->for(Certification::factory()->published())->learning()->create();
        $passed = Enrollment::factory()->for($student)->for(Certification::factory()->published())->passed()->create();
        $failed = Enrollment::factory()->for($student)->for(Certification::factory()->published())->failed()->create();

        $result = app(CreateFallbackAction::class)($student);

        $ids = $result->pluck('id')->all();
        $this->assertContains($learning->id, $ids);
        $this->assertContains($passed->id, $ids);
        $this->assertNotContains($failed->id, $ids);
        $this->assertTrue($result->first()->relationLoaded('certification'));
    }

    public function test_returns_empty_collection_when_user_is_null(): void
    {
        $result = app(CreateFallbackAction::class)(null);

        $this->assertTrue($result->isEmpty());
    }
}
