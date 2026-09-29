<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Factory;

/**
 * テスト用の `Http` ファサードの実体(T-A-04)。`Http::fake()` にマッチしない通信を、実通信へ流さず、
 * 違反として記録して 599 を返す({@see StrictPendingRequest})。
 *
 * Laravel 標準の `Http::preventStrayRequests()` は、未モックの通信で例外を投げるだけなので、呼び出し側の
 * `catch (Throwable)` で握りつぶされてテストが失敗しない。ここでは例外に頼らず、違反の記録を残す。
 */
final class StrictHttpFactory extends Factory
{
    protected function newPendingRequest()
    {
        return (new StrictPendingRequest($this, $this->globalMiddleware))->withOptions($this->globalOptions);
    }
}
