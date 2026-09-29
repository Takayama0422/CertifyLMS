<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 面談リマインダーの配信タイミング(要件シート S7、`--window` オプションの値)。
 */
enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';
}
