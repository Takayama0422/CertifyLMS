<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * T-A-04「外部 API に依存するテストをまとめて分離実行(除外)できるグループ指定」が、今後も漏れなく維持されることの検証。
 *
 * 外部連携(Google カレンダー / Gemini / Stripe)のテストダブルやモックを使うテストクラスは、
 * `#[Group('external')]` と連携別のグループ(`google-calendar` / `gemini` / `stripe`)を持つこと。
 *   - 外部依存のテストだけ実行: `php artisan test --group=external`
 *   - 外部依存のテストを除外して実行: `php artisan test --exclude-group=external`
 * 新しい外部連携のテストを追加したのにグループを付け忘れると、このテストが落ちる。
 */
class ExternalDependentTestsAreGroupedTest extends TestCase
{
    /**
     * 連携ごとの「そのテストが、その連携のモックを使っている」ことを示す目印(ソース中の文字列)。
     * 連携別グループの付け忘れの検出に使う。`Http::fake` は Gemini 以外でも使い、docblock で言及しただけの
     * ファイルも誤検知するため、ここには含めない(Gemini は API クラスまたは宛先ホスト名で判定する)。
     *
     * @var array<string, array<int, string>>
     */
    private const MARKERS = [
        'google-calendar' => ['FakeGoogleCalendarClient', 'GoogleCalendarClient::class', 'GoogleApiCalendarClient'],
        'gemini' => ['GeminiClient', 'generativelanguage'],
        'stripe' => ['FakePaymentGateway', 'PaymentGatewayContract', 'StripeWebhookTestHelpers', 'FakeStripeHttpClient', 'ApiRequestor'],
    ];

    /**
     * 「外部連携のモックを使っている」全般の目印。`Http::fake`(Gemini はこの方法でモックする)を含む。
     * これに該当するテストは、連携別かどうかに関わらず `external` グループに属すること。
     *
     * @var array<int, string>
     */
    private const ANY_EXTERNAL_MARKERS = [
        'Http::fake', 'FakeGoogleCalendarClient', 'GoogleCalendarClient::class', 'GoogleApiCalendarClient',
        'GeminiClient', 'FakePaymentGateway', 'PaymentGatewayContract', 'StripeWebhookTestHelpers',
        'FakeStripeHttpClient', 'ApiRequestor',
    ];

    /**
     * @return array<string, string> 相対パス => ソース
     */
    private function sourcesOfAllTests(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, 'Test.php')) {
                continue;
            }
            // 仕組み自体の検証(本ファイルと、ガードのテスト)は対象外
            if (str_ends_with($path, 'ExternalDependentTestsAreGroupedTest.php')) {
                continue;
            }
            $sources[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }

        return $sources;
    }

    public function test_every_test_class_using_an_external_api_double_belongs_to_the_external_group(): void
    {
        $missing = [];

        foreach ($this->sourcesOfAllTests() as $path => $source) {
            $usesExternalDouble = false;
            foreach (self::ANY_EXTERNAL_MARKERS as $marker) {
                if (str_contains($source, $marker)) {
                    $usesExternalDouble = true;
                }
            }

            if ($usesExternalDouble && ! str_contains($source, "#[Group('external')]")) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, "外部連携のモックを使うテストに #[Group('external')] が付いていない");
    }

    public function test_every_external_test_class_also_has_its_integration_specific_group(): void
    {
        $missing = [];

        foreach ($this->sourcesOfAllTests() as $path => $source) {
            // 実通信ガード自体のテストは 3 連携を横断して検証するため、連携別のグループは付けない
            if (str_starts_with($path, 'Unit/Support/')) {
                continue;
            }

            foreach (self::MARKERS as $group => $markers) {
                foreach ($markers as $marker) {
                    if (str_contains($source, $marker) && ! str_contains($source, "#[Group('{$group}')]")) {
                        $missing[] = "{$path} ({$group})";
                        break;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), '連携別のグループが付いていない');
    }
}
