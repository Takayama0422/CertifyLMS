<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use App\Services\CertificatePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 修了証 PDF の実体生成(mpdf レンダリング + private disk 保存)を検証する。
 * 日本語(氏名 / 資格名)を含むテンプレートを実際にレンダリングし、PDF バイナリが
 * `pdf_path` へ保存されることを確認する(mpdf 自体はモックしない)。
 */
class CertificatePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_pdf_file_at_certificate_pdf_path(): void
    {
        Storage::fake('private');

        $certificate = Certificate::factory()->create([
            'pdf_path' => 'certificates/test-ulid.pdf',
        ]);

        (new CertificatePdfGenerator)->generate($certificate);

        Storage::disk('private')->assertExists($certificate->pdf_path);
        $content = Storage::disk('private')->get($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF', $content);
    }

    /**
     * 氏名 / 資格名 / 発行日が実際に PDF のテキストとして描画されていることを検証する。
     *
     * 本サービスは mpdf を `mode => 'ja+aCJK'`(日本語フォント埋め込み無し、Adobe-Japan1 の
     * 非埋め込み CID フォント + `UniJIS-UTF16-H` 既定エンコーディングを使用)でレンダリングしている。
     * このモードでは PDF に `/ToUnicode` CMap が書き込まれないため、汎用の PDF テキスト抽出ライブラリ
     * (`smalot/pdfparser` を検証用に試した)は CID を正しく Unicode へ戻せず、文字化けした結果しか
     * 得られなかった(そのため依存追加は見送った)。
     *
     * 一方で `UniJIS-UTF16-H` は「文字コード = UTF-16BE のコードユニットそのもの」という Adobe 標準の
     * 既定 CMap であるため、PDF content stream 中の Tj/TJ オペランド(リテラル文字列)の生バイト列を
     * UTF-16BE として解釈するだけで、埋め込みフォントの CID→Unicode 変換テーブルを持たずに元の文字列を
     * 復元できる。本テストではこの性質を利用し、content stream を自前で最小限パースして描画済みテキストを
     * 取り出す(`extractRenderedText()`)。
     */
    public function test_pdf_contains_recipient_name_certification_name_and_issued_date(): void
    {
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00'));

        $user = User::factory()->student()->create(['name' => '山田太郎']);
        $certification = Certification::factory()->published()->create(['name' => 'Laravel認定資格']);
        $certificate = Certificate::factory()->create([
            'user_id' => $user->id,
            'certification_id' => $certification->id,
            'pdf_path' => 'certificates/test-text-ulid.pdf',
            'issued_at' => Carbon::parse('2026-08-24 10:00:00'),
        ]);

        (new CertificatePdfGenerator)->generate($certificate);

        $content = Storage::disk('private')->get($certificate->pdf_path);
        $text = $this->extractRenderedText($content);

        $this->assertStringContainsString('山田太郎', $text);
        $this->assertStringContainsString('Laravel認定資格', $text);
        $this->assertStringContainsString('2026', $text);
        $this->assertStringContainsString('8', $text);
        $this->assertStringContainsString('24', $text);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * PDF バイナリの content stream から Tj/TJ で描画された文字列を復元する。
     * `UniJIS-UTF16-H` エンコーディング前提(本サービス固有の描画設定に依存する検証専用ヘルパー)。
     */
    private function extractRenderedText(string $pdfBytes): string
    {
        $text = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $streamMatches);

        foreach ($streamMatches[1] as $raw) {
            $inflated = @zlib_decode($raw);
            if ($inflated === false) {
                continue;
            }

            preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)\s*Tj/s', $inflated, $tjMatches);
            foreach ($tjMatches[1] as $literal) {
                $text .= $this->decodeUtf16LiteralString($literal);
            }

            preg_match_all('/\[((?:[^\[\]]|\\\\.)*)\]\s*TJ/s', $inflated, $tjArrayMatches);
            foreach ($tjArrayMatches[1] as $arrayContent) {
                preg_match_all('/\(((?:\\\\.|[^()\\\\])*)\)/s', $arrayContent, $literalMatches);
                foreach ($literalMatches[1] as $literal) {
                    $text .= $this->decodeUtf16LiteralString($literal);
                }
            }
        }

        return $text;
    }

    private function decodeUtf16LiteralString(string $literal): string
    {
        $bytes = '';
        $length = strlen($literal);

        for ($i = 0; $i < $length; $i++) {
            $char = $literal[$i];

            if ($char !== '\\' || $i + 1 >= $length) {
                $bytes .= $char;

                continue;
            }

            $next = $literal[$i + 1];

            switch (true) {
                case $next === 'n': $bytes .= "\n";
                    $i++;
                    break;
                case $next === 'r': $bytes .= "\r";
                    $i++;
                    break;
                case $next === 't': $bytes .= "\t";
                    $i++;
                    break;
                case $next === 'b': $bytes .= "\x08";
                    $i++;
                    break;
                case $next === 'f': $bytes .= "\x0C";
                    $i++;
                    break;
                case $next === '(': $bytes .= '(';
                    $i++;
                    break;
                case $next === ')': $bytes .= ')';
                    $i++;
                    break;
                case $next === '\\': $bytes .= '\\';
                    $i++;
                    break;
                case $next >= '0' && $next <= '7':
                    $octal = '';
                    $j = $i + 1;
                    while ($j < $length && $j < $i + 4 && $literal[$j] >= '0' && $literal[$j] <= '7') {
                        $octal .= $literal[$j];
                        $j++;
                    }
                    $bytes .= chr(octdec($octal) & 0xFF);
                    $i = $j - 1;
                    break;
                default: $bytes .= $next;
                    $i++;
            }
        }

        $decoded = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');

        return $decoded === false ? '' : $decoded;
    }
}
