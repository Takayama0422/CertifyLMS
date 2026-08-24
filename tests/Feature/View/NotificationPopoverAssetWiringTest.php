<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * S-A-05 通知ポップオーバーを動かす JS の配線検証。
 *
 * 本チケットの主成果物は `resources/js/notifications/popover.js` だが、支給 Blade
 * (`layouts/_partials/topbar.blade.php` / `notifications/_partials/notification-popover.blade.php`)は
 * ロジックを持たない骨組みだけで、JS が読み込まれていなくても画面は静かに描画される
 * (ベルを押しても何も起きないだけで例外にならない)。HTTP レベルのテストでは検出できないため、
 * ここで「エントリから呼ばれていること」と「支給 HTML の目印を JS が取りこぼしていないこと」を
 * 構造として固定する。
 *
 * 支給 HTML 側の `data-notification-popover-*` は JS が DOM を掴むための唯一の接点であり、
 * どれか 1 つでも JS が知らない目印になれば、その部品は永久に動かないまま気づけない。
 */
class NotificationPopoverAssetWiringTest extends TestCase
{
    private const POPOVER_SCRIPT = 'resources/js/notifications/popover.js';

    private const ENTRY_SCRIPT = 'resources/js/app.js';

    private const SUPPLIED_MARKUP = [
        'resources/views/layouts/_partials/topbar.blade.php',
        'resources/views/notifications/_partials/notification-popover.blade.php',
    ];

    /**
     * JS の関与が要らない目印。フッタの「すべての通知を見る」は素の `<a href>` で、
     * JS が無効でもフルページへ遷移する(要件の「フッターのリンクから全通知のフルページへ遷移する」を満たす)。
     */
    private const HOOKS_WITHOUT_JS = [
        'data-notification-popover-footer-link',
    ];

    private function read(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path, "{$relativePath} が存在しません。");

        return (string) file_get_contents($path);
    }

    public function test_popover_module_is_imported_and_invoked_from_the_js_entry(): void
    {
        $entry = $this->read(self::ENTRY_SCRIPT);

        $this->assertStringContainsString(
            "from './notifications/popover'",
            $entry,
            'JS エントリが通知ポップオーバーのモジュールを読み込んでいません。',
        );
        $this->assertStringContainsString(
            'initNotificationPopover();',
            $entry,
            'JS エントリが initNotificationPopover() を呼び出していません。',
        );
    }

    public function test_every_supplied_dom_hook_is_handled_by_the_popover_script(): void
    {
        $script = $this->read(self::POPOVER_SCRIPT);

        $hooks = [];

        foreach (self::SUPPLIED_MARKUP as $markupPath) {
            preg_match_all('/data-notification-popover[a-z-]*/', $this->read($markupPath), $matches);
            $hooks = array_merge($hooks, $matches[0]);
        }

        $hooks = array_values(array_diff(array_unique($hooks), self::HOOKS_WITHOUT_JS));

        $this->assertNotEmpty($hooks, '支給 HTML から通知ポップオーバーの目印を検出できませんでした。');

        foreach ($hooks as $hook) {
            // JS は属性セレクタ(data-notification-popover-row)と dataset のキャメルケース
            // (notificationPopoverRow)のどちらでも参照しうるため、両方を許容する。
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', substr($hook, strlen('data-'))))));

            $this->assertTrue(
                str_contains($script, $hook) || str_contains($script, $camel),
                "支給 HTML の目印 {$hook} を JS が扱っていません。",
            );
        }
    }

    public function test_popover_script_calls_the_notification_json_api_endpoints(): void
    {
        $script = $this->read(self::POPOVER_SCRIPT);

        foreach (['/sanctum/csrf-cookie', '/api/v1/notifications', '/read-all', '/read'] as $endpoint) {
            $this->assertStringContainsString(
                $endpoint,
                $script,
                "JS が {$endpoint} を呼び出していません。",
            );
        }
    }
}
