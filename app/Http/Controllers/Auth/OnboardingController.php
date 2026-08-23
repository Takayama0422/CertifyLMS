<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OnboardingRequest;
use App\Models\Invitation;
use App\Services\InvitationTokenService;
use App\UseCases\Auth\OnboardAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function show(Request $request, Invitation $invitation, InvitationTokenService $tokenService): View|Response
    {
        // 使用済み(オンボーディング完了済み)の招待 URL への再アクセスは 410 で明示的に拒否する。
        if ($invitation->status === InvitationStatus::Accepted) {
            return response()->view('auth.invitation-invalid', [], 410);
        }

        if (! $tokenService->verify($request, $invitation)) {
            return view('auth.invitation-invalid');
        }

        $postUrl = URL::temporarySignedRoute(
            'onboarding.store',
            $invitation->expires_at,
            ['invitation' => $invitation->id],
        );

        return view('auth.onboarding', [
            'invitation' => $invitation,
            'postUrl' => $postUrl,
        ]);
    }

    public function store(
        Invitation $invitation,
        OnboardingRequest $request,
        OnboardAction $action,
    ): RedirectResponse {
        $action($invitation, $request->validated());

        return redirect()->route('dashboard.index');
    }
}
