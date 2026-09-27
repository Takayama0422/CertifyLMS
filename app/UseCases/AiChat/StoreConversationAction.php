<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI 相談の会話を新規作成 or 再利用する Action。
 *
 * - `section_id` が指定されている場合、同じ受講生 × 同じ教材の既存会話があればそれを再利用する
 *   (「教材から始めた会話は同じ教材で乱立させない」要件)。一般相談(section なし)は毎回新規作成する。
 *   再利用の判定は `createOrFirst` で行い、`ai_chat_conversations` の
 *   unique(user_id, section_id) を「先に挿入できた 1 リクエストだけが作成者になる」直列化点として使う。
 *   事前の SELECT だけで分岐すると、同じ教材から同時に送信が重なったとき両方が「既存なし」と判定して
 *   INSERT し、一意制約違反(500)になる。行ロックではなく一意制約側で受けるのは、
 *   存在しない行にはギャップロックしか掛からずデッドロックを招きやすいため。
 * - `enrollment_id` は「今の文脈」の自動付与用に解決する: section があればその教材が属する資格の
 *   Enrollment、section が無ければ受講生の既定資格(あれば)を採用する。
 * - `initialMessage` が渡された場合、会話作成後に `StoreMessageAction` で同期送信する
 *   (フル画面「新しい会話」モーダルの挙動)。ウィジェット経由は初回メッセージなしで会話だけ作る。
 *   この初回メッセージ送信は Gemini への同期通信(再試行込みで最大
 *   `ai-chat.gemini.max_total_wait_seconds` 秒)を伴うため、DB トランザクションの外で行う。
 *   外部 API の往復中にトランザクションを開いたままにすると、その間ずっと接続と行ロックを占有する。
 *   会話は先に確定させ、送信が例外で失敗した場合は作成した会話を明示的に削除して
 *   「日次上限超過等で失敗したときに空の会話を残さない」従来の性質を維持する
 *   (発言は cascadeOnDelete で一緒に消える)。
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
            $existing = $this->findExistingForSection($user, $section);

            if ($existing !== null) {
                return new AiChatConversationResult($existing, created: false);
            }
        }

        $enrollment = $this->resolveEnrollment($user, $section);
        $trimmedMessage = $initialMessage !== null ? trim($initialMessage) : '';

        $conversation = AiChatConversation::query()->createOrFirst(
            [
                'user_id' => $user->id,
                'section_id' => $section?->id,
            ],
            [
                'enrollment_id' => $enrollment?->id,
                'title' => $this->buildInitialTitle($initialMessage, $section),
                'title_manually_set' => false,
                // 一覧は last_message_at でグルーピング / 並び替えする。ウィジェットは初回メッセージ無しで
                // 会話だけ作る作りのため、ここで入れておかないと「今日」グループに入らず最下部へ落ちる。
                // メッセージが後続する場合は StoreMessageAction が送信時刻へ更新する。
                'last_message_at' => now(),
            ],
        );

        if (! $conversation->wasRecentlyCreated) {
            // 同時送信が重なり、別リクエストが先に同じ教材の会話を作っていた。事前の SELECT で
            // 既存会話を見つけた場合と同じく再利用として扱う(初回メッセージは送らない)。
            return new AiChatConversationResult($conversation, created: false);
        }

        if ($trimmedMessage !== '') {
            try {
                ($this->storeMessage)($user, $conversation, $trimmedMessage);
            } catch (Throwable $e) {
                $this->discardCreatedConversation($conversation, $e);

                throw $e;
            }
        }

        return new AiChatConversationResult($conversation->fresh(), created: true);
    }

    /**
     * 初回メッセージの送信に失敗したときの後始末。トランザクションで囲む代わりに、直前に作成した
     * 会話を明示的に消して「空の会話を残さない」性質を保つ(発言は cascadeOnDelete で一緒に消える)。
     *
     * 後始末そのものが失敗しても握り潰し、受講生へ返すべき本来の例外(日次上限なら 429)を
     * 「削除に失敗した」という別の例外へすり替えない。消し残した会話は空のまま残るだけで
     * 実害が無く、失敗した事実はログに残す。
     */
    private function discardCreatedConversation(AiChatConversation $conversation, Throwable $cause): void
    {
        try {
            $conversation->delete();
        } catch (Throwable $cleanupFailure) {
            Log::channel('ai-chat')->warning('failed to discard conversation after initial message failure', [
                'conversation_id' => $conversation->id,
                'cause' => $cause->getMessage(),
                'cleanup_error' => $cleanupFailure->getMessage(),
            ]);
        }
    }

    private function findExistingForSection(User $user, Section $section): ?AiChatConversation
    {
        return AiChatConversation::query()
            ->where('user_id', $user->id)
            ->where('section_id', $section->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->first();
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
