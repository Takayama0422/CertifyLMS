<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用のプラン一覧をキーワード / 状態フィルタ付きで取得するユースケース。
 * 各行に紐づく受講者数（`users_count`）を付与する。並び順は `sort_order` 昇順 → 作成日時降順（`Plan::scopeOrdered`）。
 */
final class IndexAction
{
    public function __invoke(
        ?string $keyword,
        ?PlanStatus $status,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Plan::query()->withCount('users')->ordered();

        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->paginate($perPage)->withQueryString();
    }
}
