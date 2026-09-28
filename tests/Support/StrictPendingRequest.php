<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\PendingRequest;

/**
 * `Http::fake()` にマッチしなかった通信が、実通信へ流れる直前で止まるようにする(T-A-04)。
 *
 * Laravel のスタブハンドラは、スタブが 1 件もマッチしなかったときだけ、内側の(実通信を行う)ハンドラを呼ぶ。
 * その内側のハンドラを「違反として記録して 599 を返す」ものへ差し替える。マッチしたスタブがある通信では
 * 呼ばれないため、正当にモックされた通信を違反と誤検知しない。
 */
final class StrictPendingRequest extends PendingRequest
{
    public function buildStubHandler()
    {
        $stubHandler = parent::buildStubHandler();

        return function ($realHandler) use ($stubHandler) {
            $blockedHandler = static function ($request, $options) {
                ExternalRequestGuard::record('http', $request->getMethod().' '.$request->getUri());

                return Create::promiseFor(new Response(
                    599,
                    ['Content-Type' => 'application/json'],
                    '{"error":"stray external request blocked by test guard"}',
                ));
            };

            return $stubHandler($blockedHandler);
        };
    }
}
