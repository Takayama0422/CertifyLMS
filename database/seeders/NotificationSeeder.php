<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\BusinessEventNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 通知(S-B-04)のデモデータシーダー。
 *
 * **設計思想**: 固定の受講生・コーチ(`student@certify-lms.test` / `coach@certify-lms.test`)に、
 * 既読・未読を混在させ、種別の異なる通知(chat / qa 回答 / 面談予約 / 面談キャンセル)を混在させる
 * (要件シート S9)。一覧・ページネーション・行クリック遷移を実機で確認できる状態にする。
 *
 * 実際の通知クラス(`toArray()`)を使って data のキー構成を実装本体と一致させつつ、`notify()` は呼ばず
 * (メール送信 / afterCommit の副作用を避けるため)`DatabaseNotification` へ直接 INSERT する。
 *
 * 依存順序: UserSeeder → EnrollmentSeeder → MentoringSeeder(Meeting)→ ChatSeeder(ChatMessage)→
 * QaBoardSeeder(QaReply)の後に走る前提。参照先が無ければ該当種別のみスキップする。
 */
final class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        $coach = User::query()->where('email', 'coach@certify-lms.test')->first();

        if ($student === null || $coach === null) {
            $this->command?->warn('NotificationSeeder: 固定アカウントが存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $now = now();
        $sequence = 0;

        // --- student 宛: chat / qa 回答 / 面談予約 / 面談キャンセルを混在(既読 2 件 + 未読 2 件) ---
        $studentRoom = ChatRoom::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $student->id))
            ->first();
        $chatMessageForStudent = $studentRoom !== null
            ? ChatMessage::query()
                ->where('chat_room_id', $studentRoom->id)
                ->where('sender_user_id', '!=', $student->id)
                ->latest('created_at')
                ->first()
            : null;

        if ($chatMessageForStudent !== null) {
            $this->seed($student, new ChatMessageReceivedNotification($chatMessageForStudent), read: false, createdAt: $now->copy()->subHours(++$sequence));
        }

        $qaReplyForStudent = QaReply::query()
            ->whereHas('thread', fn ($q) => $q->where('user_id', $student->id))
            ->where('user_id', '!=', $student->id)
            ->latest('created_at')
            ->first();
        if ($qaReplyForStudent !== null) {
            $this->seed($student, new QaReplyReceivedNotification($qaReplyForStudent), read: false, createdAt: $now->copy()->subHours(++$sequence));
        }

        $reservedMeetingForStudent = Meeting::query()
            ->where('student_id', $student->id)
            ->where('status', MeetingStatus::Reserved->value)
            ->latest('created_at')
            ->first();
        if ($reservedMeetingForStudent !== null) {
            $this->seed($student, new MeetingReservedNotification($reservedMeetingForStudent), read: true, createdAt: $now->copy()->subDays(1)->subHours(++$sequence));
        }

        $canceledMeetingForStudent = Meeting::query()
            ->where('student_id', $student->id)
            ->where('status', MeetingStatus::Canceled->value)
            ->latest('created_at')
            ->first();
        if ($canceledMeetingForStudent !== null) {
            $this->seed($student, new MeetingCanceledNotification($canceledMeetingForStudent), read: true, createdAt: $now->copy()->subDays(2)->subHours(++$sequence));
        }

        // --- coach 宛: chat / 面談予約 / 面談キャンセルを混在(既読 1 件 + 未読 2 件、qa 回答は受講生専用のため対象外) ---
        $chatMessageForCoach = ChatMessage::query()
            ->whereHas('chatRoom.members', fn ($q) => $q->where('user_id', $coach->id))
            ->where('sender_user_id', '!=', $coach->id)
            ->latest('created_at')
            ->first();
        if ($chatMessageForCoach !== null) {
            $this->seed($coach, new ChatMessageReceivedNotification($chatMessageForCoach), read: false, createdAt: $now->copy()->subHours(++$sequence));
        }

        $reservedMeetingForCoach = Meeting::query()
            ->where('coach_id', $coach->id)
            ->where('status', MeetingStatus::Reserved->value)
            ->latest('created_at')
            ->first();
        if ($reservedMeetingForCoach !== null) {
            $this->seed($coach, new MeetingReservedNotification($reservedMeetingForCoach), read: false, createdAt: $now->copy()->subHours(++$sequence));
        }

        $canceledMeetingForCoach = Meeting::query()
            ->where('coach_id', $coach->id)
            ->where('status', MeetingStatus::Canceled->value)
            ->latest('created_at')
            ->first();
        if ($canceledMeetingForCoach !== null) {
            $this->seed($coach, new MeetingCanceledNotification($canceledMeetingForCoach), read: true, createdAt: $now->copy()->subDays(3)->subHours(++$sequence));
        }
    }

    private function seed(User $recipient, BusinessEventNotification $notification, bool $read, Carbon $createdAt): void
    {
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $recipient->id,
            'data' => $notification->toArray($recipient),
            'read_at' => $read ? $createdAt->copy()->addMinutes(30) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
