<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを物理削除するユースケース。公開中の面談パックは削除不可（過去の購入履歴の整合性を守るため）。
 */
final class DestroyAction
{
    /**
     * @throws MeetingPackNotDeletableException 公開中の面談パックは削除不可
     */
    public function __invoke(MeetingPack $meetingPack): void
    {
        if ($meetingPack->status === MeetingPackStatus::Published) {
            throw new MeetingPackNotDeletableException;
        }

        DB::transaction(fn () => $meetingPack->delete());
    }
}
