<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 目標受験日超過の learning Enrollment を failed に自動遷移(他バッチと時刻被りなしで先頭起動)
        $schedule->command('enrollments:fail-expired')->dailyAt('00:00')->withoutOverlapping(5);

        // 期限切れ Invitation の cascade 処理
        $schedule->command('invitations:expire')->dailyAt('00:30')->withoutOverlapping(5);

        // プラン期間満了による自動 graduated 遷移（invitations:expire とロック競合しないよう 00:45 にずらす）
        $schedule->command('users:graduate-expired')->dailyAt('00:45')->withoutOverlapping(5);

        // 滞留 open 学習セッションを max_session_seconds で強制クローズ(ブラウザ閉じ / PC スリープ等の保険)
        $schedule->command('learning:close-stale-sessions')
            ->dailyAt(config('learning.close_stale_schedule', '01:00'))
            ->withoutOverlapping(5);

        // 終了時刻超過の reserved 面談を completed に自動遷移(15 分間隔でリアルタイム性確保)
        $schedule->command('meetings:auto-complete')->cron('*/15 * * * *')->withoutOverlapping(5);

        // 面談リマインダー — 前日 20:00 を軸に、20:00-23:00 の毎時起動で 1 回分の失敗を後続の回が
        // 拾えるようにする(Action 側は日付のみで判定するため、この時間帯なら何回起動しても安全)。
        // 重複防止は Action 側の UNIQUE 制約(sent_at 確定後のみ配信済とみなす)で保証。
        $schedule->command('notifications:send-meeting-reminders --window=eve')
            ->cron('0 20-23 * * *')
            ->withoutOverlapping(5);

        // 面談リマインダー — 実行時点から 55〜65 分後に開始する面談(10 分幅)を 10 分間隔で走査する。
        // コーチ対応可能時間帯は分単位で登録できる(面談開始が毎時 00 分とは限らない)ため、
        // 起動間隔(10 分)を対象幅(10 分)以下に保ち、連続する起動の走査範囲を隙間なく繋げることで、
        // どの分オフセットの面談も必ずいずれかの起動で窓に入る。**この間隔を対象幅より広げると
        // その差分の面談が取りこぼされる**(窓を過ぎた面談を後から拾う経路は無い)。
        // 窓の境界に重なった面談の二重配信は Action 側の UNIQUE 制約で防止する。
        $schedule->command('notifications:send-meeting-reminders --window=one_hour_before')
            ->everyTenMinutes()
            ->withoutOverlapping(5);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
