<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用の面談パック一覧をキーワード / 状態フィルタ付きで取得するユースケース。
 * 並び順は 公開中優先 → `sort_order` 昇順 → 作成日時降順（`MeetingPack::scopeOrdered`）。
 * 一覧の「購入数」列（`payments_count`）は決済ステータスで絞り込まず、
 * 詳細画面の購入数カード（`$plan->payments->count()`）と同じく全 Payment を数える。
 */
final class IndexAction
{
    public function __invoke(
        ?string $keyword,
        ?MeetingPackStatus $status,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = MeetingPack::query()->withCount('payments')->ordered();

        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->paginate($perPage)->withQueryString();
    }
}
