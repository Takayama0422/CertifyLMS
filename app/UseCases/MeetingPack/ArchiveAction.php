<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックをアーカイブ（published → archived）するユースケース。
 * 公開中以外の状態からの呼出は MeetingPackInvalidTransitionException（409）。
 */
final class ArchiveAction
{
    /**
     * @throws MeetingPackInvalidTransitionException 公開中以外からの呼出
     */
    public function __invoke(MeetingPack $meetingPack, User $admin): MeetingPack
    {
        if ($meetingPack->status !== MeetingPackStatus::Published) {
            throw MeetingPackInvalidTransitionException::forArchive();
        }

        return DB::transaction(function () use ($meetingPack, $admin) {
            $meetingPack->update([
                'status' => MeetingPackStatus::Archived->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $meetingPack->fresh();
        });
    }
}
