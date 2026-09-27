<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Enums\MeetingReminderWindow;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Console\Command;

/**
 * 予約済み面談の前日 / 開始 1 時間前リマインダーを配信する定期実行コマンド(要件シート S7)。
 *
 * `--window=eve`(前日 20:00 起動)/ `--window=one_hour_before`(毎時 00 分起動)の
 * 2 つのスケジュールをそれぞれ独立に登録する(`app/Console/Kernel.php`)。
 * 重複起動・再実行しても二重配信しない(`SendMeetingRemindersAction` が UNIQUE 制約で保証)。
 */
class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : eve または one_hour_before}';

    protected $description = '予約済み面談の前日 / 開始 1 時間前リマインダーをアプリ内通知 + メールで配信する';

    public function handle(SendMeetingRemindersAction $action): int
    {
        $window = MeetingReminderWindow::tryFrom((string) $this->option('window'));

        if ($window === null) {
            $this->error('--window は eve または one_hour_before のいずれかを指定してください。');

            return self::FAILURE;
        }

        $count = $action($window);

        $this->info("面談リマインダー({$window->value})を {$count} 件配信しました。");

        return self::SUCCESS;
    }
}
