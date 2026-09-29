<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * admin 用の面談パック詳細を取得するユースケース。
 * 作成者 / 最終更新者に加え、購入履歴(Payment、直近 20 件・購入者を含む)を Eager Loading する。
 * 単一モデルへの `load()` のため `limit()` はこのパック 1 件分にのみ適用され、N+1 グループ化の問題は生じない。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $meetingPack): MeetingPack
    {
        return $meetingPack->load([
            'createdBy',
            'updatedBy',
            'payments' => fn ($query) => $query->with('user')->orderByDesc('created_at')->limit(20),
        ]);
    }
}
