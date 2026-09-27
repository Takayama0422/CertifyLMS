<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Section;
use App\UseCases\LearningSession\StartAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 教材 Section 閲覧時に LearningSession をサーバ側で自動開始するミドルウェア。
 *
 * 別 Section へ遷移するたびに旧 open セッションを閉じて新規開始する「自動切替」の副作用を担う。
 * 認可通過後 (Controller が描画を終えた後) に実行し、失敗は閲覧の妨げにしないため例外吸収してログのみ残す
 * (残骸 open は learning:close-stale-sessions Schedule Command で後追い回収する)。
 *
 * Laravel の Routing Pipeline は Controller / Action が投げた例外 (403 の AuthorizationException や
 * 404 の NotFoundHttpException など) をこの handle() に届く前に Response へ変換してしまうため、
 * $next($request) の戻り値だけでは認可・存在チェックを通過したか判別できない。成功レスポンス
 * (2xx) のときのみセッションを開始するようガードし、403 / 404 を返したリクエストで学習セッションが
 * 作成されたり、既存の open セッションが誤って自動クローズされたりしないようにする。
 */
final class StartLearningSession
{
    public function __construct(
        private readonly StartAction $startSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $section = $request->route('section');
        $user = $request->user();

        if ($response->isSuccessful() && $section instanceof Section && $user !== null) {
            try {
                ($this->startSession)($user, $section);
            } catch (\Throwable $exception) {
                Log::warning('LearningSession auto-start failed', [
                    'user_id' => $user->id,
                    'section_id' => $section->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $response;
    }
}
