<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;

/**
 * admin 用のプラン詳細を取得するユースケース。作成者 / 最終更新者 / 紐づく受講者一覧を Eager Loading する。
 */
final class ShowAction
{
    public function __invoke(Plan $plan): Plan
    {
        return $plan->load(['createdBy', 'updatedBy', 'users']);
    }
}
