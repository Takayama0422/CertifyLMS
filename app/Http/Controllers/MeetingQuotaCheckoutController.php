<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutIndexRequest;
use App\Http\Requests\MeetingQuota\CheckoutStoreRequest;
use App\Http\Requests\MeetingQuota\SuccessRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\UseCases\Payment\CreateCheckoutSessionAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 追加面談パックの購入動線(S-A-03)。受講生(学習中)本人のみ。
 *
 * 画面(purchase 選択 / 完了)は `resources/views/meeting-quota/` に提供済みのため、
 * 本 Controller は route 名 `meeting-quota.checkout.select` / `meeting-quota.checkout.create` /
 * `meeting-quota.success` が要求する変数を組み立てるだけの薄い層に徹する。
 */
class MeetingQuotaCheckoutController extends Controller
{
    public function index(CheckoutIndexRequest $request): View
    {
        return view('meeting-quota.checkout-select', [
            'plans' => MeetingPack::query()->published()->ordered()->get(),
        ]);
    }

    public function store(CheckoutStoreRequest $request, CreateCheckoutSessionAction $action): RedirectResponse
    {
        $plan = MeetingPack::query()->findOrFail($request->validated('meeting_pack_id'));

        $this->authorize('purchase', $plan);

        // Stripe の Checkout Session 生成時に {CHECKOUT_SESSION_ID} を実際の Session ID へ置換してもらうための
        // プレースホルダ。route() の第二引数(クエリ配列)経由で組み立てると { } が URL エンコードされ
        // Stripe 側が置換できなくなるため、生成済み URL に文字列として直接付与する。
        $successUrl = route('meeting-quota.success').'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('meeting-quota.checkout.select');

        $checkoutUrl = $action($request->user(), $plan, $successUrl, $cancelUrl);

        return redirect()->away($checkoutUrl);
    }

    public function success(SuccessRequest $request): View
    {
        $sessionId = $request->validated('session_id');

        $payment = $sessionId !== null
            ? Payment::query()
                ->where('user_id', $request->user()->id)
                ->where('stripe_checkout_session_id', $sessionId)
                ->with('meetingPack')
                ->first()
            : null;

        return view('meeting-quota.success', [
            'payment' => $payment,
        ]);
    }
}
