<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

/**
 * API キー未設定時に「利用できない旨の案内」を表示する共通処理。
 *
 * 支給済み画面(`resources/views/ai-chat/*`)は変更対象外で専用の案内 UI を追加できないため、
 * 既存の共通フラッシュ表示口(`<x-flash />`, `resources/views/components/flash.blade.php`)に
 * 乗せる形で案内する(画面ごと 404 にする機能 OFF スイッチとは別軸)。
 */
trait FlashesAiChatAvailability
{
    private function flashUnavailableNoticeIfKeyMissing(): void
    {
        $apiKey = config('ai-chat.gemini.api_key');

        if ($apiKey === null || $apiKey === '') {
            session()->flash('warning', 'AI 相談は現在ご利用いただけません(AI 連携が未設定です)。しばらくしてから再度お試しください。');
        }
    }
}
