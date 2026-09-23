<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを公開（draft → published）するユースケース。
 * 下書き以外からの遷移は不正で MeetingPackInvalidTransitionException（409）。
 */
final class PublishAction
{
    /**
     * @throws MeetingPackInvalidTransitionException 下書き以外からの呼出
     */
    public function __invoke(MeetingPack $meetingPack, User $admin): MeetingPack
    {
        if ($meetingPack->status !== MeetingPackStatus::Draft) {
            throw MeetingPackInvalidTransitionException::forPublish();
        }

        return DB::transaction(function () use ($meetingPack, $admin) {
            $meetingPack->update([
                'status' => MeetingPackStatus::Published->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $meetingPack->fresh();
        });
    }
}
