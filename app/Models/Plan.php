<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受講プラン マスタ。duration_days(受講期間) + default_meeting_quota(初期付与面談回数) のセット。
 *
 * 価格情報は LMS 内では保持しない(決済は LMS 外で完結)。受講生招待時に Plan を指定して User.plan_* 系カラムを初期化する。
 *
 * 関連: User(plan_id) / UserPlanLog(履歴) / Admin(created_by / updated_by)
 */
class Plan extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'description',
        'duration_days',
        'default_meeting_quota',
        'status',
        'sort_order',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'status' => PlanStatus::class,
        'duration_days' => 'integer',
        'default_meeting_quota' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<UserPlanLog, $this>
     */
    public function userPlanLogs(): HasMany
    {
        return $this->hasMany(UserPlanLog::class);
    }

    /**
     * @param Builder<Plan> $query
     *
     * @return Builder<Plan>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PlanStatus::Published);
    }

    /**
     * 公開中を優先し、下書き・アーカイブが続く並び順(`app/Models/MeetingPack.php` と同じ考え方)。
     * 同順位内は sort_order 昇順 → created_at 降順。
     *
     * @param Builder<Plan> $query
     *
     * @return Builder<Plan>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $query->orderByRaw("FIELD(status, 'published', 'draft', 'archived')");
        } else {
            // SQLite では FIELD() が使えないため CASE 式で同等の優先順位を表現する
            $query->orderByRaw(
                "CASE status WHEN 'published' THEN 1 WHEN 'draft' THEN 2 WHEN 'archived' THEN 3 ELSE 4 END"
            );
        }

        return $query->orderBy('sort_order')->orderByDesc('created_at');
    }
}
