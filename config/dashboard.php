<?php

declare(strict_types=1);

/**
 * 管理者ダッシュボード集計キャッシュの設定。
 *
 * T-A-06: 全体 KPI(adminKpi)と資格別修了率(completionRateByCertification)は
 * 全 Enrollment を走査する重い集計のため、一定時間(TTL)キャッシュして連続表示での
 * 再計算を避ける。受講状態遷移(EnrollmentStatusChangeService::recordStatusChange)を
 * 経路とする変化があった場合は、この TTL を待たずに即時無効化する。
 */
return [
    // 全体 KPI(learning / passed / failed 件数)集計のキャッシュキー
    'admin_kpi_cache_key' => 'dashboard.admin.kpi',

    // 資格別修了率集計のキャッシュキー
    'admin_completion_rate_cache_key' => 'dashboard.admin.completion_rate',

    // 上記 2 キーの保存時間(秒)。既定 300 秒(5 分)。環境変数で調整可能
    'admin_cache_ttl' => (int) env('DASHBOARD_ADMIN_CACHE_TTL', 300),
];
