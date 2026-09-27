<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * admin 用の面談パック詳細を取得するユースケース。
 * 作成者 / 最終更新者を Eager Loading する。購入履歴（Payment）は S-A-03 未実装のため本 Action では扱わず、
 * view 側が `class_exists(\App\Models\Payment::class)` で出し分ける。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $meetingPack): MeetingPack
    {
        return $meetingPack->load(['createdBy', 'updatedBy']);
    }
}
