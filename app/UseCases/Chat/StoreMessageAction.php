<?php

declare(strict_types=1);

namespace App\UseCases\Chat;

use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Services\NotificationRecipientService;
use Illuminate\Support\Facades\DB;

/**
 * ChatRoom にメッセージを INSERT し、送信者の既読時刻を更新したうえで Broadcast を発火する Action。
 *
 * - INSERT 後、ChatMessage::booted() が `chat_rooms.last_message_at` を denormalize 更新する
 * - 送信者自身の `ChatMember.last_read_at = now()` を UPDATE(自分のメッセージは未読としてカウントしない)
 * - 通信失敗が DB 整合性に波及しないよう Pusher Broadcast は `DB::afterCommit()` で送る
 * - 担当コーチ未割当の判定は Controller 側で実施済(`CertificationCoachNotAssignedForChatException` 振り分け)
 * - 送信者以外のルーム参加者(相手方)へ、配信対象の除外規則(`NotificationRecipientService`)を通した
 *   うえでアプリ内通知 + メール(`ChatMessageReceivedNotification`)を afterCommit で発火する
 */
final class StoreMessageAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(User $sender, ChatRoom $room, array $validated): ChatMessage
    {
        return DB::transaction(function () use ($sender, $room, $validated) {
            $message = ChatMessage::create([
                'chat_room_id' => $room->id,
                'sender_user_id' => $sender->id,
                'body' => $validated['body'],
            ]);

            ChatMember::query()
                ->where('chat_room_id', $room->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => now()]);

            DB::afterCommit(function () use ($message, $sender, $room): void {
                broadcast(new ChatMessageSent($message->load('sender')))->toOthers();

                $recipients = ChatMember::query()
                    ->with('user')
                    ->where('chat_room_id', $room->id)
                    ->where('user_id', '!=', $sender->id)
                    ->get()
                    ->pluck('user')
                    ->filter(fn (?User $user) => $user !== null && NotificationRecipientService::eligibleForEventNotification($user));

                foreach ($recipients as $recipient) {
                    $recipient->notify(new ChatMessageReceivedNotification($message));
                }
            });

            return $message;
        });
    }
}
