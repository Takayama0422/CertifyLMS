<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| worktree ローカル PSR-4 オーバーライド
|--------------------------------------------------------------------------
|
| このリポジトリの `vendor/` は git worktree 間で共有するためのシンボリックリンクであり、
| `vendor/composer/autoload_static.php` 内の `__DIR__ . '/../../app'` 等の相対パスは
| PHP が symlink を実体パスへ解決してしまう都合上、常にリンク先(symlink の実体側の
| チェックアウト)の `app/` / `database/` / `tests/` を指してしまう。
| そのままでは本 worktree で追加・変更したクラスが一切読み込まれない
| (常にリンク先の同名ファイルが使われてしまう)ため、`composer.json` の autoload 設定と
| 同じマッピングを本 worktree の実パスで再登録し、Composer のクラスローダーより先に解決させる。
|
| `vendor/` 配下のパッケージ本体(サードパーティ)には一切影響しない(App\ / Database\Factories\ /
| Database\Seeders\ / Tests\ の 4 namespace のみを本 worktree のパスへ固定するだけ)。
|
| 呼び出し元: `bootstrap/app.php`(artisan / 通常の HTTP リクエスト経由)と
| `tests/bootstrap.php`(`phpunit.xml` の bootstrap 属性、`php artisan test` / phpunit 直接実行の両方)。
| 二重登録を避けるため static フラグでガードする。
|
*/
if (! defined('AI_CHAT_WORKTREE_AUTOLOAD_REGISTERED')) {
    define('AI_CHAT_WORKTREE_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        static $prefixes = [
            'App\\' => __DIR__.'/../app/',
            'Database\\Factories\\' => __DIR__.'/../database/factories/',
            'Database\\Seeders\\' => __DIR__.'/../database/seeders/',
            'Tests\\' => __DIR__.'/../tests/',
        ];

        foreach ($prefixes as $prefix => $baseDir) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir.str_replace('\\', '/', $relative).'.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }, true, true);
}
