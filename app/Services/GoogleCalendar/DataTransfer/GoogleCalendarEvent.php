<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar\DataTransfer;

use Carbon\CarbonInterface;

/**
 * Google カレンダーへ登録する 1 件の予定を表す値オブジェクト。
 *
 * Meet URL は生成しない(スコープ外)。`description` にコーチの固定面談 URL を焼き込む。
 */
final readonly class GoogleCalendarEvent
{
    public function __construct(
        public string $summary,
        public ?string $description,
        public CarbonInterface $start,
        public CarbonInterface $end,
    ) {}
}
