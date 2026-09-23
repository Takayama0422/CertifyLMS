<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

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
        // 署名が無い/不正なリクエストには、招待の状態(使用済みかどうか含む)を一切開示しない。
        // 必ず署名検証を先に行い、そのうえで招待の状態を判定する。
        if (! $request->hasValidSignature()) {
            return view('auth.invitation-invalid');
        }

        // 使用済み(オンボーディング完了済み)の招待 URL への再アクセスは 410 で明示的に拒否する。
        // 判定は Invitation::isUsed() に寄せ、Controller では状態を直接比較しない。
        if ($invitation->isUsed()) {
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
