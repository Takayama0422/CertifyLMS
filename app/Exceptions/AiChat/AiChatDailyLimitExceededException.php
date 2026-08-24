<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 受講生 1 人・1 日あたりの AI 相談メッセージ送信上限に達した状態で送信しようとした場合に throw する例外。
 *
 * 429 Too Many Requests を返す。JSON 経由(ウィジェット / フル画面いずれも fetch で
 * `Accept: application/json` を送るため常に該当)はステータスコードのみで足りる
 * (`resources/js/ai-chat/chat-client.js` は body を読まず status だけ見て分岐する)。
 *
 * 429 は `Handler::REDIRECT_BACK_STATUSES`(409 / 422)に含まれないため、万一 JS 無効等で
 * 素の HTML フォーム POST(新規会話モーダルに初回メッセージを添えて送信 → 上限超過)が届いた場合に
 * 備え、本クラス自身に `render()` を定義して同じ「直前ページへ戻し + Flash error」に倣う。
 */
final class AiChatDailyLimitExceededException extends HttpException
{
    public function __construct(?string $message = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            message: $message ?? '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。',
            previous: $previous,
        );
    }

    public function render(Request $request): ?RedirectResponse
    {
        if ($request->expectsJson()) {
            return null;
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}
