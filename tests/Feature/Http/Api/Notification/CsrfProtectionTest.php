<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cookie ベース認証(Sanctum SPA)の CSRF 対策の検証(S-A-05: 「なりすまし・偽装リクエストを防ぐ」)。
 *
 * `api` ミドルウェアグループの `EnsureFrontendRequestsAreStateful` は、リクエストが stateful ドメイン
 * (Origin/Referer が一致)から来た場合のみ CSRF 検証ミドルウェア(config/sanctum.php の
 * `verify_csrf_token`)を差し込む(vendor/laravel/sanctum の実装で確認済み)。
 *
 * Laravel の `VerifyCsrfToken` は `runningInConsole() && runningUnitTests()` の場合に検証をスキップする
 * (PHPUnit は常に CLI から実行されるため、既定では素通りしてしまう)。本テストは
 * `$this->app->instance('env', 'production')` で一時的にこの短絡を外し、実際の検証経路を通す。
 * JS 側(resources/js/utils/fetch-json.js)は同一オリジン運用に合わせて `X-CSRF-TOKEN` ヘッダ(セッション
 * トークンそのもの)を送る実装のため、ここでも同じ経路を検証する。
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stateful_request_without_csrf_token_is_rejected(): void
    {
        $this->app->instance('env', 'production');

        $user = User::factory()->student()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['_token' => 'test-csrf-token'])
            ->withHeaders(['Origin' => 'http://localhost'])
            ->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(419);
    }

    public function test_stateful_request_with_matching_csrf_token_succeeds(): void
    {
        $this->app->instance('env', 'production');

        $user = User::factory()->student()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['_token' => 'test-csrf-token'])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => 'test-csrf-token',
            ])
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk();
    }

    public function test_stateful_request_with_mismatched_csrf_token_is_rejected(): void
    {
        $this->app->instance('env', 'production');

        $user = User::factory()->student()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['_token' => 'test-csrf-token'])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-CSRF-TOKEN' => 'wrong-token',
            ])
            ->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(419);
    }
}
