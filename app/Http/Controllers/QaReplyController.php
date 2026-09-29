<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QaReply\StoreRequest;
use App\Http\Requests\QaReply\UpdateRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction;
use App\UseCases\QaReply\StoreAction;
use App\UseCases\QaReply\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 質問掲示板の回答(公開画面)Controller。受講生 / コーチ共通(`/qa-board/{thread}/replies`)。
 * 管理者は回答を投稿できない(ルート自体を admin ロールへ許可しない)。
 */
class QaReplyController extends Controller
{
    public function store(StoreRequest $request, QaThread $thread, StoreAction $action): RedirectResponse
    {
        $action($thread, $request->user(), $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を投稿しました。');
    }

    public function edit(QaThread $thread, QaReply $reply): View
    {
        $this->guardReplyBelongsToThread($thread, $reply);
        $this->authorize('update', $reply);

        return view('qa-thread.reply-edit', ['thread' => $thread, 'reply' => $reply]);
    }

    public function update(UpdateRequest $request, QaThread $thread, QaReply $reply, UpdateAction $action): RedirectResponse
    {
        $this->guardReplyBelongsToThread($thread, $reply);

        $action($reply, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を更新しました。');
    }

    public function destroy(QaThread $thread, QaReply $reply, DestroyAction $action): RedirectResponse
    {
        $this->guardReplyBelongsToThread($thread, $reply);
        $this->authorize('delete', $reply);

        $action($reply);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '回答を削除しました。');
    }

    /**
     * `{thread}/replies/{reply}` の 2 つの route model binding が独立して解決されるため、
     * 別スレッドの reply id を紛れ込ませた URL(データ不整合な参照)を 404 で弾く。
     */
    private function guardReplyBelongsToThread(QaThread $thread, QaReply $reply): void
    {
        abort_unless($reply->qa_thread_id === $thread->id, 404);
    }
}
