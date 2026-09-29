<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 「モックしていない外部通信」の発生を記録する(T-A-04)。
 *
 * 外部連携の呼び出し側(`GoogleCalendarService` / `GeminiClient` 等)は、通信失敗を握りつぶして
 * フォールバックする作りのため、未モックの通信を「例外で止める」だけではテストが失敗せず素通りする。
 * そこで違反をここへ記録し、`Tests\TestCase::assertPostConditions()` で「記録が 1 件でもあればテスト失敗」とする。
 */
final class ExternalRequestGuard
{
    /** @var array<int, string> */
    private static array $violations = [];

    public static function record(string $channel, string $detail): void
    {
        self::$violations[] = "[{$channel}] {$detail}";
    }

    /**
     * @return array<int, string>
     */
    public static function violations(): array
    {
        return self::$violations;
    }

    public static function reset(): void
    {
        self::$violations = [];
    }
}
