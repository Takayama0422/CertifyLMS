<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Http\Requests\QaThread\IndexRequest;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\AdminDestroyAction as ReplyAdminDestroyAction;
use App\UseCases\QaThread\AdminDestroyAction as ThreadAdminDestroyAction;
use App\UseCases\QaThread\IndexAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 質問掲示板の管理者モデレーション Controller(`/admin/qa-board`)。
 * `resources/views/qa-thread/` を公開画面(`QaThreadController`)と共用する(`$isAdminContext` で出し分け)。
 * 管理者はスレッド / 回答の削除のみ可能(内容編集・解決マーク代行は提供しない、チケットのスコープ外)。
 */
class QaThreadModerationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $viewer = $request->user();
        $filters = $request->filters();

        return view('qa-thread.index', [
            'threads' => $action($viewer, $filters),
            'filters' => $filters,
            'certifications' => Certification::query()->orderBy('name')->get(),
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function show(QaThread $thread): View
    {
        $this->authorize('view', $thread);

        $thread->load(['user', 'certification', 'replies.user'])->loadCount('replies');

        return view('qa-thread.show', ['thread' => $thread]);
    }

    public function destroy(QaThread $thread, ThreadAdminDestroyAction $action): RedirectResponse
    {
        $this->authorize('moderateDelete', $thread);

        $action($thread);

        return redirect()
            ->route('admin.qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    public function destroyReply(QaThread $thread, QaReply $reply, ReplyAdminDestroyAction $action): RedirectResponse
    {
        abort_unless($reply->qa_thread_id === $thread->id, 404);
        $this->authorize('moderateDelete', $reply);

        $action($reply);

        return redirect()
            ->route('admin.qa-board.show', $thread)
            ->with('success', '回答を削除しました。');
    }
}
