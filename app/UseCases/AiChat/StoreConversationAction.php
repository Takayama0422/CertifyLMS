<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AI 相談の会話を新規作成 or 再利用する Action。
 *
 * - `section_id` が指定されている場合、同じ受講生 × 同じ教材の既存会話があればそれを再利用する
 *   (「教材から始めた会話は同じ教材で乱立させない」要件)。一般相談(section なし)は毎回新規作成する。
 * - `enrollment_id` は「今の文脈」の自動付与用に解決する: section があればその教材が属する資格の
 *   Enrollment、section が無ければ受講生の既定資格(あれば)を採用する。
 * - `initialMessage` が渡された場合、会話作成後に `StoreMessageAction` で同期送信する
 *   (フル画面「新しい会話」モーダルの挙動)。ウィジェット経由は初回メッセージなしで会話だけ作る。
 *   会話作成とこの初回メッセージ送信は 1 トランザクションにまとめる。日次上限超過等で
 *   `StoreMessageAction` が例外を投げた場合、作成直後の空の会話を残さずロールバックするため。
 */
final class StoreConversationAction
{
    public function __construct(private readonly StoreMessageAction $storeMessage) {}

    public function __invoke(User $user, ?string $sectionId, ?string $initialMessage): AiChatConversationResult
    {
        $section = $sectionId !== null
            ? Section::query()->find($sectionId)
            : null;

        if ($section !== null) {
            $existing = AiChatConversation::query()
                ->where('user_id', $user->id)
                ->where('section_id', $section->id)
                ->orderByDesc('last_message_at')
                ->orderByDesc('created_at')
                ->first();

            if ($existing !== null) {
                return new AiChatConversationResult($existing, created: false);
            }
        }

        $enrollment = $this->resolveEnrollment($user, $section);
        $trimmedMessage = $initialMessage !== null ? trim($initialMessage) : '';

        $conversation = DB::transaction(function () use ($user, $section, $enrollment, $initialMessage, $trimmedMessage) {
            $conversation = AiChatConversation::create([
                'user_id' => $user->id,
                'section_id' => $section?->id,
                'enrollment_id' => $enrollment?->id,
                'title' => $this->buildInitialTitle($initialMessage, $section),
                'title_manually_set' => false,
                // 一覧は last_message_at でグルーピング / 並び替えする。ウィジェットは初回メッセージ無しで
                // 会話だけ作る作りのため、ここで入れておかないと「今日」グループに入らず最下部へ落ちる。
                // メッセージが後続する場合は StoreMessageAction が送信時刻へ更新する。
                'last_message_at' => now(),
            ]);

            if ($trimmedMessage !== '') {
                ($this->storeMessage)($user, $conversation, $trimmedMessage);
            }

            return $conversation;
        });

        return new AiChatConversationResult($conversation->fresh(), created: true);
    }

    private function resolveEnrollment(User $user, ?Section $section): ?Enrollment
    {
        if ($section !== null) {
            $section->loadMissing('chapter.part');
            $certificationId = $section->chapter?->part?->certification_id;

            if ($certificationId !== null) {
                $enrollment = Enrollment::query()
                    ->where('user_id', $user->id)
                    ->where('certification_id', $certificationId)
                    ->where('status', EnrollmentStatus::Learning->value)
                    ->orderByDesc('created_at')
                    ->first();

                if ($enrollment !== null) {
                    return $enrollment;
                }

                $enrollment = Enrollment::query()
                    ->where('user_id', $user->id)
                    ->where('certification_id', $certificationId)
                    ->orderByDesc('created_at')
                    ->first();

                if ($enrollment !== null) {
                    return $enrollment;
                }
            }
        }

        if ($user->default_enrollment_id !== null) {
            return $user->defaultEnrollment;
        }

        return Enrollment::query()
            ->where('user_id', $user->id)
            ->where('status', EnrollmentStatus::Learning->value)
            ->orderByDesc('created_at')
            ->first();
    }

    private function buildInitialTitle(?string $initialMessage, ?Section $section): string
    {
        $trimmed = $initialMessage !== null ? trim($initialMessage) : '';

        if ($trimmed !== '') {
            return Str::limit($trimmed, 40, '');
        }

        if ($section !== null) {
            // 末尾に "..." を足す既定の Str::limit だと 100 文字の制限を超えて保存に失敗する
            // (DB カラムは string(100), 厳格モード)ため、記号を足さない形で 100 文字以内に収める。
            return Str::limit("{$section->title} についての相談", 100, '');
        }

        return '新しい相談';
    }
}
