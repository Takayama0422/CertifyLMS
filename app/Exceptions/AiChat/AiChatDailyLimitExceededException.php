<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 受講生 1 人・1 日あたりの AI 相談メッセージ送信上限に達した状態で送信しようとした場合に throw する例外。
 *
 * 429 Too Many Requests を返す。JSON 経由(ウィジェット / フル画面いずれも fetch で
 * `Accept: application/json` を送るため常に該当)はステータスコードのみで足りる
 * (`resources/js/ai-chat/chat-client.js` は body を読まず status だけ見て分岐する)。
 *
 * 他のドメイン例外 8 件と同様、自前の `render()` は持たない。HTML 経由(万一 JS 無効等で
 * 素の HTML フォーム POST が届いた場合)の「直前ページへ戻し + Flash error」への変換は、
 * `Handler::REDIRECT_BACK_STATUSES` に一元化する(429 を追加済み)。
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
}
