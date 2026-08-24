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
    ],

    'daily_message_limit' => (int) env('AI_CHAT_DAILY_MESSAGE_LIMIT', 30),

    'history_limit' => (int) env('AI_CHAT_HISTORY_LIMIT', 20),

    'auto_title' => [
        'enabled' => (bool) env('AI_CHAT_AUTO_TITLE_ENABLED', true),
    ],
];
