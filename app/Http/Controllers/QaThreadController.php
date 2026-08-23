<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Http\Requests\QaThread\IndexRequest;
use App\Http\Requests\QaThread\StoreRequest;
use App\Http\Requests\QaThread\UpdateRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\DestroyAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ResolveAction;
use App\UseCases\QaThread\StoreAction;
use App\UseCases\QaThread\UnresolveAction;
use App\UseCases\QaThread\UpdateAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 質問掲示板(公開画面)Controller。受講生 / コーチ共通(`/qa-board`)。
 * `resources/views/qa-thread/` は管理者モデレーション画面(`QaThreadModerationController`)とも共用する
 * (`$isAdminContext` で表示・遷移先を出し分ける、Blade 側のヘッダコメント参照)。
 * スレッド削除の 409(回答あり)は `App\Exceptions\QaThread\QaThreadHasRepliesException` →
 * `App\Exceptions\Handler` のドメイン例外 redirect-back 変換に委ねる(Controller で catch しない)。
 */
class QaThreadController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $viewer = $request->user();
        $filters = $request->filters();

        return view('qa-thread.index', [
            'threads' => $action($viewer, $filters),
            'filters' => $filters,
            'certifications' => $this->visibleCertifications($viewer),
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', QaThread::class);

        return view('qa-thread.create', [
            'certifications' => Certification::published()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $thread = $action($request->user(), $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を投稿しました。');
    }

    public function show(QaThread $thread): View
    {
        $this->authorize('view', $thread);

        $thread->load(['user', 'certification', 'replies.user'])->loadCount('replies');

        return view('qa-thread.show', ['thread' => $thread]);
    }

    public function edit(QaThread $thread): View
    {
        $this->authorize('update', $thread);

        return view('qa-thread.edit', ['thread' => $thread]);
    }

    public function update(UpdateRequest $request, QaThread $thread, UpdateAction $action): RedirectResponse
    {
        $action($thread, $request->validated());

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を更新しました。');
    }

    public function destroy(QaThread $thread, DestroyAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    public function resolve(QaThread $thread, ResolveAction $action): RedirectResponse
    {
        $this->authorize('resolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を解決済にしました。');
    }

    public function unresolve(QaThread $thread, UnresolveAction $action): RedirectResponse
    {
        $this->authorize('unresolve', $thread);

        $action($thread);

        return redirect()
            ->route('qa-board.show', $thread)
            ->with('success', '質問を未解決に戻しました。');
    }

    /**
     * 絞り込みフォームの資格チップに出す一覧(閲覧者から見える資格のみ)。
     *
     * @return Collection<int, Certification>
     */
    private function visibleCertifications(User $viewer): Collection
    {
        return match ($viewer->role) {
            UserRole::Coach => Certification::published()
                ->whereIn('id', $viewer->coachingCertificationIds())
                ->orderBy('name')
                ->get(),
            default => Certification::published()->orderBy('name')->get(),
        };
    }
}
