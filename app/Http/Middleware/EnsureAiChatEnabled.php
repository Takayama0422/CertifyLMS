<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI 相談機能全体の ON/OFF スイッチ(`config('ai-chat.enabled')`)。
 *
 * OFF の場合、`ai-chat.*` ルート群を丸ごと 404 にする(画面・経路ごと利用不可)。
 * API キー未設定は別軸(機能自体は有効だが個々の応答が失敗する)のため、本 Middleware では扱わない。
 */
final class EnsureAiChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('ai-chat.enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
