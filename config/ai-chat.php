<?php

declare(strict_types=1);

/**
 * AI 相談(Gemini AI チャットボット, S-A-02)の設定。
 *
 * - `enabled` が false の場合、`EnsureAiChatEnabled` Middleware が全 ai-chat ルートを 404 にする
 *   (画面・経路ごと利用不可)。
 * - `gemini.api_key` が未設定でもアプリ自体は正常に起動・動作する。未設定時は
 *   `GeminiClient::generateReply()` が「鍵未設定」を表す失敗結果を返し、Controller / Action 側で
 *   「利用できない旨の案内」に変換する(S-A-02 非機能要件)。
 * - `daily_message_limit`: 受講生 1 人・1 日あたりのメッセージ送信上限(既定 30)。AI 応答が失敗した
 *   送信も上限にカウントする(失敗分の補正はスコープ外)。
 * - `history_limit`: AI への入力に引き継ぐ直近メッセージ件数(自前のトークン切り詰めはスコープ外のため
 *   件数制御のみ)。
 * - `auto_title.enabled`: 初回 AI 応答完了後に会話タイトルを AI が自動生成する機能の ON/OFF スイッチ。
 *   受講生が手動でタイトル編集した会話には適用しない。
 * - `gemini.max_total_wait_seconds`: 通信が全滅した場合でも `timeout` × 試行回数の合計でここまでしか
 *   待たない上限(超えたら以後は再試行を打ち切る)。受講生のリクエストを長時間ブロックしないための保険。
 * - `system_prompt.*`: Gemini へ渡すシステムプロンプト(AI への指示文)。管理画面 / DB 管理は仕様の
 *   スコープ外のため、環境変数のみで完結させる。`:title` / `:name` は動的に置換されるプレースホルダ。
 */
return [
    'enabled' => (bool) env('AI_CHAT_ENABLED', true),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'retry_times' => (int) env('GEMINI_RETRY_TIMES', 2),
        'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 200),
        'max_total_wait_seconds' => (int) env('GEMINI_MAX_TOTAL_WAIT_SECONDS', 45),
    ],

    'system_prompt' => [
        'base' => env(
            'AI_CHAT_SYSTEM_PROMPT_BASE',
            'あなたは資格学習支援 LMS 「Certify LMS」の AI 学習アシスタントです。'
            .'受講生の学習相談に日本語で簡潔かつ丁寧に答えてください。断定できない専門的な内容は「参考情報」である旨を添えてください。',
        ),
        'section_context' => env(
            'AI_CHAT_SYSTEM_PROMPT_SECTION_CONTEXT',
            '受講生は現在教材「:title」を閲覧しながら質問しています。可能であればこの教材の文脈を踏まえて回答してください。',
        ),
        'certification_context' => env(
            'AI_CHAT_SYSTEM_PROMPT_CERTIFICATION_CONTEXT',
            '受講生は資格「:name」の取得を目指して学習中です。',
        ),
    ],

    'daily_message_limit' => (int) env('AI_CHAT_DAILY_MESSAGE_LIMIT', 30),

    'history_limit' => (int) env('AI_CHAT_HISTORY_LIMIT', 20),

    'auto_title' => [
        'enabled' => (bool) env('AI_CHAT_AUTO_TITLE_ENABLED', true),
    ],
];
