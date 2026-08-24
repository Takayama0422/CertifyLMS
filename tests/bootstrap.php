<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| `phpunit.xml` の bootstrap 属性からここが呼ばれる(`php artisan test` は内部で phpunit を
| 起動するが、phpunit 自体は artisan / bootstrap/app.php を経由しないため、Composer の
| クラスローダーだけでは本 worktree のクラスが解決できない問題を避けるための追加登録)。
|
| 詳細は bootstrap/worktree-autoload.php のコメントを参照。
|
*/
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../bootstrap/worktree-autoload.php';
