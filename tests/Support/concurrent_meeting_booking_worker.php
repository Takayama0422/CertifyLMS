<?php

declare(strict_types=1);
use App\Http\Controllers\MeetingController;
use App\Http\Requests\Meeting\StoreRequest;
use App\Models\Enrollment;
use App\Services\CoachMeetingLoadService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Redirector;

/**
 * B-A-01 の並行予約回帰テスト (MeetingBookingConcurrencyTest) から
 * Symfony\Component\Process\Process で 2 プロセス同時起動される Worker スクリプト。
 *
 * 単体の PHP プロセスとして Laravel アプリを起動し、自分専用の DB コネクションで
 * MeetingController::store() を直接呼び出す。PHPUnit の in-process 実行(1 コネクション・
 * 1 トランザクション)では真の同時実行を再現できないため、実プロセスを分けて
 * (coach_id, scheduled_at) UNIQUE 制約への同時 INSERT 競合を実際に発生させる。
 *
 * 引数: studentId enrollmentId scheduledAtIso topic barrierFile outFile
 *
 * outFile には 1 行目に「本処理(store())へ入った瞬間の microtime」、2 行目に結果文字列を書く。
 * 呼び出し側テストは 2 プロセスの 1 行目同士の差が十分小さいことを検証し、
 * 実際に競合したこと(片方が完了した後に他方が逐次実行されたのではないこと)を確認する。
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $studentId, $enrollmentId, $scheduledAtIso, $topic, $barrierFile, $outFile] = $argv;

// 開始時刻バリア: 2 プロセスがほぼ同時に本処理(可用性チェック→INSERT)へ入るよう同期する。
$target = (float) trim((string) file_get_contents($barrierFile));
while (microtime(true) < $target) {
    usleep(500);
}

$enteredAt = microtime(true);

try {
    $enrollment = Enrollment::query()->with('user')->findOrFail($enrollmentId);
    $student = $enrollment->user;
    if ($student === null || $student->id !== $studentId) {
        throw new RuntimeException('enrollment.user が想定の受講生と一致しない');
    }

    $request = new StoreRequest;
    $request->initialize(
        [],
        ['scheduled_at' => $scheduledAtIso, 'topic' => $topic],
        [],
        [],
        [],
        ['REQUEST_METHOD' => 'POST'],
    );
    $request->setContainer($app);
    $request->setUserResolver(fn () => $student);
    $request->setRouteResolver(fn () => new class($enrollment)
    {
        public function __construct(private Enrollment $enrollment) {}

        public function parameter($name, $default = null)
        {
            return $name === 'enrollment' ? $this->enrollment : $default;
        }
    });
    $request->setRedirector($app->make(Redirector::class));
    $request->validateResolved();

    $controller = $app->make(MeetingController::class);
    $controller->store(
        $enrollment,
        $request,
        $app->make(MeetingAvailabilityService::class),
        $app->make(CoachMeetingLoadService::class),
        $app->make(MeetingQuotaService::class),
        $app->make(ConsumeQuotaAction::class),
    );

    file_put_contents($outFile, $enteredAt.PHP_EOL.'OK');
} catch (Throwable $e) {
    file_put_contents($outFile, $enteredAt.PHP_EOL.get_class($e).': '.$e->getMessage());
}
