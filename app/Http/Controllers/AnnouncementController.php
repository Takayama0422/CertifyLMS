<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Announcement\StoreRequest;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\UseCases\Announcement\DispatchAnnouncementAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 管理者お知らせ配信(S-B-08)Controller。管理画面配下、admin のみ。
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Announcement::class);

        $announcements = Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByDesc('dispatched_at')
            ->paginate(20);

        return view('announcement.management.index', ['announcements' => $announcements]);
    }

    public function create(): View
    {
        $this->authorize('create', Announcement::class);

        return view('announcement.management.create', [
            'certifications' => Certification::published()->orderBy('name')->get(),
            'students' => User::query()
                ->where('role', UserRole::Student->value)
                ->where('status', UserStatus::InProgress->value)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreRequest $request, DispatchAnnouncementAction $action): RedirectResponse
    {
        $announcement = $action($request->user(), $request->validated());

        return redirect()
            ->route('admin.announcements.show', $announcement)
            ->with('success', 'お知らせを配信しました。');
    }

    public function show(Announcement $announcement): View
    {
        $this->authorize('view', $announcement);

        $announcement->loadMissing(['targetCertification', 'targetUser', 'createdBy']);

        return view('announcement.management.show', ['announcement' => $announcement]);
    }
}
