<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * 開発用 AI 相談(Gemini AI チャットボット, S-A-02)シーダー。
 *
 * チケット原文の「初期データ」要件を満たす:
 * - 固定受講生(`student@certify-lms.test`)に複数の会話を投入し、過去の相談の再開 / 履歴表示
 *   (今日 / 過去 7 日 / 過去 30 日のグルーピング)を確認できるようにする
 * - 会話の中に AI 応答がエラー状態のものを含める(エラー表示・同じ内容の再送 UI の確認用)
 * - 教材から始めた会話(section_id あり)・教材によらない一般相談の両方を含める
 *
 * 依存順序: `UserSeeder` → `EnrollmentSeeder` → `ContentSeeder`(Section)→ 本 Seeder。
 */
final class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($student === null) {
            $this->command?->warn('AiChatSeeder: 固定 student (student@certify-lms.test) が見つかりません。先に UserSeeder を実行してください。');

            return;
        }

        $enrollment = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('status', EnrollmentStatus::Learning->value)
            ->orderBy('created_at')
            ->first();

        if ($enrollment === null) {
            $this->command?->warn('AiChatSeeder: 固定 student の Enrollment (learning) が見つかりません。先に EnrollmentSeeder を実行してください。');

            return;
        }

        $section = Section::query()
            ->published()
            ->whereHas('chapter.part', fn ($q) => $q->where('certification_id', $enrollment->certification_id))
            ->orderBy('created_at')
            ->first();

        $this->seedGeneralConversation($student, $enrollment);
        $this->seedSectionConversation($student, $enrollment, $section);
        $this->seedErrorConversation($student, $enrollment);
        $this->seedOlderConversation($student, $enrollment);
    }

    /**
     * 教材によらない一般相談(今日、last_message_at = 直近)。
     */
    private function seedGeneralConversation(User $student, Enrollment $enrollment): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'section_id' => null,
            'title' => '学習の進め方について',
            'title_manually_set' => false,
        ]);

        $this->appendExchange(
            $conversation,
            $student,
            userBody: '模試の点数が伸び悩んでいます。次に何を優先して復習すべきですか？',
            assistantBody: '直近の模試結果を見ると、正答率が低い分野から優先的に復習するのが効率的です。'
                .'苦手分野ドリルの「弱点」タブから、間違いが多いカテゴリを絞り込んで解き直してみてください。',
            minutesAgo: 30,
        );

        $conversation->forceFill(['last_message_at' => Carbon::now()->subMinutes(28)])->save();
    }

    /**
     * 教材(Section)から始めた相談(今日、Section 文脈バッジが表示される)。
     */
    private function seedSectionConversation(User $student, Enrollment $enrollment, ?Section $section): void
    {
        if ($section === null) {
            $this->command?->warn('AiChatSeeder: 公開済み Section が見つからないため、教材紐づき会話の投入をスキップします。');

            return;
        }

        $conversation = AiChatConversation::create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
            'title' => "{$section->title} についての相談",
            'title_manually_set' => false,
        ]);

        $this->appendExchange(
            $conversation,
            $student,
            userBody: 'このセクションの要点を3行でまとめてください。',
            assistantBody: "承知しました。このセクションの要点は次の3点です。\n"
                ."1. 基本的な考え方の整理\n2. 典型的な誤答パターン\n3. 試験での頻出ポイント\n"
                .'不明な点があれば具体的にどこで詰まっているか教えてください。',
            minutesAgo: 90,
        );

        $conversation->forceFill(['last_message_at' => Carbon::now()->subMinutes(85)])->save();
    }

    /**
     * AI 応答がエラー状態の会話(過去 7 日、エラー表示・再送 UI の確認用)。
     * 受講生の質問メッセージ自体は Completed のまま残る。
     */
    private function seedErrorConversation(User $student, Enrollment $enrollment): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'section_id' => null,
            'title' => '過去問の解き方について',
            'title_manually_set' => false,
        ]);

        $createdAt = Carbon::now()->subDays(2);

        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => '過去問を解く時間配分のコツを教えてください。',
        ]);
        $userMessage->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $errorAt = $createdAt->copy()->addSeconds(5);
        $assistantMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
            'content' => '',
            'error_detail' => 'Gemini API error (HTTP 503): The model is overloaded. Please try again later.',
        ]);
        $assistantMessage->forceFill(['created_at' => $errorAt, 'updated_at' => $errorAt])->save();

        $conversation->forceFill(['last_message_at' => $errorAt])->save();
    }

    /**
     * 過去 30 日バケットのグルーピング確認用の古い会話。
     */
    private function seedOlderConversation(User $student, Enrollment $enrollment): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'section_id' => null,
            'title' => '受験までのスケジュールの立て方',
            'title_manually_set' => true,
        ]);

        $this->appendExchange(
            $conversation,
            $student,
            userBody: '試験日まで残り2ヶ月です。学習計画の立て方のアドバイスをください。',
            assistantBody: '残り期間から逆算し、直近1ヶ月はインプット、最後の1ヶ月は演習と模試中心に配分するのがおすすめです。'
                .'週ごとの目標を「学習時間目標」機能で設定しておくと進捗が可視化できます。',
            minutesAgo: 60,
            baseAt: Carbon::now()->subDays(20),
        );

        $conversation->forceFill(['last_message_at' => Carbon::now()->subDays(20)->addMinutes(5)])->save();
    }

    private function appendExchange(
        AiChatConversation $conversation,
        User $student,
        string $userBody,
        string $assistantBody,
        int $minutesAgo,
        ?Carbon $baseAt = null,
    ): void {
        $base = $baseAt ?? Carbon::now()->subMinutes($minutesAgo);

        $userMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => $userBody,
        ]);
        $userMessage->forceFill(['created_at' => $base, 'updated_at' => $base])->save();

        $replyAt = $base->copy()->addSeconds(4);
        $assistantMessage = AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'user_id' => $student->id,
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => $assistantBody,
            'model' => 'gemini-2.5-flash',
            'input_tokens' => random_int(120, 420),
            'output_tokens' => random_int(60, 260),
            'response_time_ms' => random_int(600, 2400),
        ]);
        $assistantMessage->forceFill(['created_at' => $replyAt, 'updated_at' => $replyAt])->save();
    }
}
