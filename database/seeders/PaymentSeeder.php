<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\PurchaseQuotaAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * 開発用 追加面談購入(Payment)デモシーダー(S-A-03)。
 *
 * **設計思想**: 決済状態を網羅(succeeded / pending / failed)して投入し、
 * 面談パック管理画面の購入履歴・受講生の面談回数履歴の両方で「状態ごとの見え方」を実機確認できるようにする。
 * `succeeded` のみ実際に `PurchaseQuotaAction` を通して面談回数へ反映する(pending / failed は残数に影響しない、
 * という本チケットの核心要件をデモデータの生成経路自体でも守る = 本番コードと同じ Action を再利用する)。
 */
class PaymentSeeder extends Seeder
{
    public function __construct(
        private readonly PurchaseQuotaAction $purchaseQuotaAction,
    ) {}

    public function run(): void
    {
        $packs = MeetingPack::query()->where('status', MeetingPackStatus::Published->value)->orderBy('sort_order')->get();

        if ($packs->isEmpty()) {
            $this->command?->warn('PaymentSeeder: 公開中の面談パックが存在しません。先に MeetingPackSeeder を実行してください。');

            return;
        }

        $fixedStudent = User::query()
            ->where('email', 'student@certify-lms.test')
            ->first();

        $demoStudent = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->when($fixedStudent, fn ($q) => $q->whereKeyNot($fixedStudent->id))
            ->orderBy('created_at')
            ->first();

        foreach (array_filter([$fixedStudent, $demoStudent]) as $index => $student) {
            $this->seedForStudent($student, $packs, $index);
        }
    }

    /**
     * @param Collection<int, MeetingPack> $packs
     */
    private function seedForStudent(User $student, $packs, int $index): void
    {
        // 完了(残数に反映済み)
        $succeeded = $this->createPayment($student, $packs[0], PaymentStatus::Succeeded);
        ($this->purchaseQuotaAction)($succeeded);

        // 保留(決済サービスからの通知待ち、残数は未反映)
        $this->createPayment($student, $packs[$index % $packs->count()], PaymentStatus::Pending);

        // 失敗(決済サービス側でキャンセル/期限切れ、残数は未反映)
        if ($packs->count() > 1) {
            $this->createPayment($student, $packs[1], PaymentStatus::Failed);
        }
    }

    /**
     * 実際の Checkout Session ID を持たないデモ用ダミー ID を振る
     * (本物の Stripe セッションと衝突しないよう `seed_` プレフィックスを付ける)。
     */
    private function createPayment(User $student, MeetingPack $pack, PaymentStatus $status): Payment
    {
        return Payment::create([
            'meeting_pack_id' => $pack->id,
            'user_id' => $student->id,
            'status' => $status->value,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
            'stripe_checkout_session_id' => 'seed_'.$student->id.'_'.$pack->id.'_'.$status->value,
            'stripe_payment_intent_id' => $status === PaymentStatus::Succeeded ? 'seed_pi_'.$student->id.'_'.$pack->id : null,
            'paid_at' => $status === PaymentStatus::Succeeded ? now() : null,
        ]);
    }
}
