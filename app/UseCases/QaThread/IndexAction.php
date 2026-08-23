<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * 質問掲示板スレッド一覧の取得ユースケース。公開画面(受講生 / コーチ)と管理者モデレーション画面の両方から使う。
 *
 * 可視範囲:
 * - student: 公開中の資格のスレッドすべて
 * - coach: 担当資格 かつ 公開中の資格のスレッドのみ
 * - admin: 全件(公開停止中の資格を含む)
 *
 * N+1 対策: `user` / `certification` を eager load、回答数は `withCount('replies')` で 1 クエリに集約する
 * (詳細は `tests/Feature/Http/QaThread/IndexQueryCountTest.php` で回帰検証)。新着順・20 件ページネーション。
 *
 * @param array{status?: ?string, certification_id?: ?string, keyword?: ?string} $filters
 */
final class IndexAction
{
    private const PER_PAGE = 20;

    public function __invoke(User $viewer, array $filters): LengthAwarePaginator
    {
        $query = QaThread::query()
            ->with(['user', 'certification'])
            ->withCount('replies');

        $query = $this->scopeToVisibility($query, $viewer);
        $query = $this->applyFilters($query, $filters);

        return $query->latest()->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    private function scopeToVisibility(Builder $query, User $viewer): Builder
    {
        return match ($viewer->role) {
            UserRole::Admin => $query,
            UserRole::Coach => $query
                ->whereIn('certification_id', $viewer->coachingCertificationIds())
                ->whereHas('certification', fn (Builder $q) => $q->published()),
            default => $query->whereHas('certification', fn (Builder $q) => $q->published()),
        };
    }

    /**
     * @param Builder<QaThread> $query
     * @param array{status?: ?string, certification_id?: ?string, keyword?: ?string} $filters
     *
     * @return Builder<QaThread>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $status = $filters['status'] ?? null;
        if ($status === 'resolved') {
            $query->resolved();
        } elseif ($status === 'unresolved') {
            $query->unresolved();
        }

        $certificationId = $filters['certification_id'] ?? null;
        if ($certificationId !== null && $certificationId !== '') {
            $query->where('certification_id', $certificationId);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('title', 'LIKE', '%'.$keyword.'%')
                    ->orWhere('body', 'LIKE', '%'.$keyword.'%');
            });
        }

        return $query;
    }
}
