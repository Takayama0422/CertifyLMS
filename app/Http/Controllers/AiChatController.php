<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FlashesAiChatAvailability;
use App\Models\AiChatConversation;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI 相談トップ(`GET /ai-chat`)。
 *
 * 受講生に会話が 1 件でもあれば直近の会話へ redirect し(chat.index と同じ「サイドバー → 即詳細」の
 * UX)、0 件なら empty-state を表示する。
 */
class AiChatController extends Controller
{
    use FlashesAiChatAvailability;

    public function index(Request $request): Renderable|RedirectResponse
    {
        $this->authorize('viewAny', AiChatConversation::class);

        $this->flashUnavailableNoticeIfKeyMissing();

        $latest = AiChatConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest !== null) {
            return redirect()->route('ai-chat.conversations.show', $latest);
        }

        return view('ai-chat.empty-state');
    }
}
