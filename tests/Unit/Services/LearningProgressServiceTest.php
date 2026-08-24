<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Services\Learning\ProgressSummary;
use App\Services\LearningProgressService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 学習進捗集計 Service `LearningProgressService` の検証。
 *
 * T-A-03: 受講登録詳細 (ShowEnrollmentAction / EnrollmentController) と
 * 受講生ダッシュボード (FetchStudentDashboardAction)、Part 詳細 (ShowPartAction) に
 * 重複していた進捗集計ロジックを本 Service へ集約した。ここでは各高レベルメソッドの
 * 完了数・完了率・端数処理・非公開コンテンツの除外を検証する。
 */
class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_reports_full_completion_across_all_levels(): void
    {
        // Arrange: Part2 / Chapter3 / Section4 構成の教材を全読了
        $enrollment = Enrollment::factory()->learning()->create();
        [$sections] = $this->buildCertificationTree($enrollment->certification_id);

        foreach ($sections as $section) {
            SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
        }

        // Act
        $summary = app(LearningProgressService::class)->summarize($enrollment);

        // Assert
        $this->assertInstanceOf(ProgressSummary::class, $summary);
        $this->assertSame(4, $summary->sectionsTotal);
        $this->assertSame(4, $summary->sectionsCompleted);
        $this->assertSame(1.0, $summary->sectionCompletionRatio);
        $this->assertSame(3, $summary->chaptersTotal);
        $this->assertSame(3, $summary->chaptersCompleted);
        $this->assertSame(1.0, $summary->chapterCompletionRatio);
        $this->assertSame(2, $summary->partsTotal);
        $this->assertSame(2, $summary->partsCompleted);
        $this->assertSame(1.0, $summary->partCompletionRatio);
        $this->assertSame(1.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_partial_completion_rounds_ratio_to_four_decimals(): void
    {
        // Arrange: Chapter1(2 Section) 完了 / Chapter2(1 Section) 未完了 / Chapter3(1 Section) 完了
        // → Section 3/4=0.75、Chapter 2/3=0.6667(四捨五入)、Part 1/2=0.5 のはず
        $enrollment = Enrollment::factory()->learning()->create();
        [$sections] = $this->buildCertificationTree($enrollment->certification_id);
        // $sections = [chapter1-section1, chapter1-section2, chapter2-section1, chapter3-section1]
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sections[0])->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sections[1])->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($sections[3])->create();

        // Act
        $summary = app(LearningProgressService::class)->summarize($enrollment);

        // Assert
        $this->assertSame(4, $summary->sectionsTotal);
        $this->assertSame(3, $summary->sectionsCompleted);
        $this->assertSame(0.75, $summary->sectionCompletionRatio);
        $this->assertSame(3, $summary->chaptersTotal);
        $this->assertSame(2, $summary->chaptersCompleted, 'Chapter1・Chapter3 は完了、Chapter2 は未完了のはず');
        $this->assertSame(0.6667, $summary->chapterCompletionRatio);
        $this->assertSame(2, $summary->partsTotal);
        $this->assertSame(1, $summary->partsCompleted, 'Part1 は Chapter2 が未完了のため未完了、Part2 のみ完了のはず');
        $this->assertSame(0.5, $summary->partCompletionRatio);
        $this->assertSame(0.75, $summary->overallCompletionRatio, 'overallCompletionRatio は Section 単位の比率と一致するはず');
    }

    public function test_summarize_returns_zero_ratios_without_division_error_when_certification_has_no_published_content(): void
    {
        // Arrange: 公開コンテンツが 1 つも無い資格
        $enrollment = Enrollment::factory()->learning()->create();

        // Act
        $summary = app(LearningProgressService::class)->summarize($enrollment);

        // Assert
        $this->assertSame(0, $summary->sectionsTotal);
        $this->assertSame(0.0, $summary->sectionCompletionRatio);
        $this->assertSame(0, $summary->chaptersTotal);
        $this->assertSame(0.0, $summary->chapterCompletionRatio);
        $this->assertSame(0, $summary->partsTotal);
        $this->assertSame(0.0, $summary->partCompletionRatio);
        $this->assertSame(0.0, $summary->overallCompletionRatio);
    }

    public function test_summarize_excludes_draft_content_from_totals(): void
    {
        // Arrange: 公開 Part の他に、非公開(draft) Part を同一資格へ追加
        $enrollment = Enrollment::factory()->learning()->create();
        $this->buildCertificationTree($enrollment->certification_id);

        $draftPart = Part::factory()->draft()->forCertification($enrollment->certification)->create();
        $draftChapter = Chapter::factory()->draft()->forPart($draftPart)->create();
        Section::factory()->draft()->forChapter($draftChapter)->create();

        // Act
        $summary = app(LearningProgressService::class)->summarize($enrollment);

        // Assert: draft の Part/Chapter/Section は総数に含まれない
        $this->assertSame(4, $summary->sectionsTotal);
        $this->assertSame(3, $summary->chaptersTotal);
        $this->assertSame(2, $summary->partsTotal);
    }

    /**
     * コードレビュー指摘 9(T-A-03)の回帰テスト。
     *
     * 既存の `test_summarize_excludes_draft_content_from_totals` は Part/Chapter/Section を
     * すべて非公開にしているため、3 つの絞り込み(parts.status / chapters.status / sections.status)の
     * うちどれか 1 つでも効いていれば除外され、どの条件が効いているか判別できない。
     * ここでは「公開 Part 配下の非公開 Chapter」を単独で置き、chapters.status の絞り込みだけを検証する。
     */
    public function test_summarize_excludes_sections_under_draft_chapter_even_when_part_is_published(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        [, $certification] = $this->buildCertificationTree($enrollment->certification_id);

        // 公開 Part 直下に非公開 Chapter を追加し、その配下には公開 Section を置く
        // (Section 自体は公開なので、chapters.status の絞り込みが効いていなければ総数に混入する)。
        $publishedPart = Part::factory()->published()->forCertification($certification)->create();
        $draftChapter = Chapter::factory()->draft()->forPart($publishedPart)->create();
        Section::factory()->published()->forChapter($draftChapter)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        // buildCertificationTree() のベース分(Part2/Chapter3/Section4)に、公開 Part 1 件だけが加わり、
        // 非公開 Chapter とその配下の Section は総数に含まれないはず。
        $this->assertSame(4, $summary->sectionsTotal, '非公開 Chapter 配下の公開 Section が混入している');
        $this->assertSame(3, $summary->chaptersTotal, '非公開 Chapter 自体が総数に混入している');
        $this->assertSame(3, $summary->partsTotal, '公開 Part の追加分が反映されていない');
    }

    /**
     * コードレビュー指摘 9(T-A-03)の回帰テスト。
     * 「公開 Chapter 配下の非公開 Section」を単独で置き、sections.status の絞り込みだけを検証する。
     */
    public function test_summarize_excludes_draft_section_even_when_chapter_and_part_are_published(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        [$sections] = $this->buildCertificationTree($enrollment->certification_id);

        // 公開 Chapter1 配下に非公開 Section を追加する。
        $chapter1 = $sections[0]->chapter;
        Section::factory()->draft()->forChapter($chapter1)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(4, $summary->sectionsTotal, '非公開 Section が総数に混入している');
        $this->assertSame(3, $summary->chaptersTotal, '非公開 Section の追加で Chapter 総数が変わってはならない');
    }

    /**
     * コードレビュー指摘 10(T-A-03)の回帰テスト。
     * 別の受講登録(同一資格)の読了実績が、対象の受講登録の集計に混ざらないことを検証する。
     */
    public function test_summarize_does_not_count_other_enrollments_progress(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        [$sections, $certification] = $this->buildCertificationTree($enrollment->certification_id);

        $otherEnrollment = Enrollment::factory()->learning()->for($certification)->create();
        foreach ($sections as $section) {
            SectionProgress::factory()->forEnrollment($otherEnrollment)->forSection($section)->create();
        }

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(4, $summary->sectionsTotal);
        $this->assertSame(0, $summary->sectionsCompleted, '他受講登録の読了実績が混ざってはならない');
        $this->assertSame(0.0, $summary->sectionCompletionRatio);
        $this->assertSame(0, $summary->chaptersCompleted);
        $this->assertSame(0, $summary->partsCompleted);
    }

    /**
     * コードレビュー指摘 10(T-A-03)の回帰テスト。
     * 非公開 Section に対する読了実績(通常は生成され得ないデータ)が、万一存在しても
     * 完了数に含まれないことを検証する。
     */
    public function test_summarize_does_not_count_progress_on_a_non_public_section(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        [, $certification] = $this->buildCertificationTree($enrollment->certification_id);

        $part = Part::factory()->published()->forCertification($certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $draftSection = Section::factory()->draft()->forChapter($chapter)->create();
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($draftSection)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(4, $summary->sectionsTotal, '非公開 Section は総数に含まれないはず');
        $this->assertSame(0, $summary->sectionsCompleted, '非公開 Section への読了実績が完了数に混入している');
    }

    /**
     * コードレビュー指摘 10(T-A-03)の回帰テスト。
     * 公開 Section が 0 件の Chapter(全 Section が非公開)を完了扱いにしないことを検証する
     * (total=0 かつ done=0 のとき `total === done` が真になり得るため、total > 0 の判定漏れを検出する)。
     */
    public function test_summarize_does_not_treat_chapter_with_zero_published_sections_as_completed(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $certification = $enrollment->certification;

        $part = Part::factory()->published()->forCertification($certification)->create();
        $emptyChapter = Chapter::factory()->published()->forPart($part)->create();
        Section::factory()->draft()->forChapter($emptyChapter)->create();

        $summary = app(LearningProgressService::class)->summarize($enrollment);

        $this->assertSame(1, $summary->chaptersTotal);
        $this->assertSame(0, $summary->chaptersCompleted, '公開 Section が 0 件の Chapter を完了扱いにしてはならない');
        $this->assertSame(1, $summary->partsTotal);
        $this->assertSame(0, $summary->partsCompleted);
    }

    public function test_batch_section_completion_ratios_returns_empty_array_for_empty_collection(): void
    {
        // Act
        $result = app(LearningProgressService::class)->batchSectionCompletionRatios(new EloquentCollection);

        // Assert
        $this->assertSame([], $result);
    }

    public function test_batch_section_completion_ratios_matches_per_enrollment_summarize_ratio(): void
    {
        // Arrange: 異なる資格の 2 Enrollment を用意し、それぞれ異なる完了率にする
        $enrollmentA = Enrollment::factory()->learning()->create();
        [$sectionsA] = $this->buildCertificationTree($enrollmentA->certification_id);
        SectionProgress::factory()->forEnrollment($enrollmentA)->forSection($sectionsA[0])->create();
        SectionProgress::factory()->forEnrollment($enrollmentA)->forSection($sectionsA[1])->create();

        $enrollmentB = Enrollment::factory()->learning()->create();
        [$sectionsB] = $this->buildCertificationTree($enrollmentB->certification_id);
        foreach ($sectionsB as $section) {
            SectionProgress::factory()->forEnrollment($enrollmentB)->forSection($section)->create();
        }

        $service = app(LearningProgressService::class);
        $enrollments = Enrollment::query()->whereIn('id', [$enrollmentA->id, $enrollmentB->id])->get();

        // Act
        $ratios = $service->batchSectionCompletionRatios($enrollments);

        // Assert: 個別 summarize() の sectionCompletionRatio と一致するはず
        $this->assertSame($service->summarize($enrollmentA->fresh())->sectionCompletionRatio, $ratios[$enrollmentA->id]);
        $this->assertSame($service->summarize($enrollmentB->fresh())->sectionCompletionRatio, $ratios[$enrollmentB->id]);
        $this->assertSame(0.5, $ratios[$enrollmentA->id]);
        $this->assertSame(1.0, $ratios[$enrollmentB->id]);
    }

    public function test_section_completion_counts_by_chapter_returns_empty_array_when_enrollment_is_null(): void
    {
        // Arrange
        $part = Part::factory()->published()->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        Section::factory()->published()->forChapter($chapter)->create();
        $chapters = Chapter::query()->where('id', $chapter->id)->get();

        // Act
        $result = app(LearningProgressService::class)->sectionCompletionCountsByChapter($chapters, null);

        // Assert
        $this->assertSame([], $result);
    }

    public function test_section_completion_counts_by_chapter_groups_done_counts_and_omits_chapters_without_progress(): void
    {
        // Arrange: Chapter1 は 2 Section 中 1 件読了、Chapter2 は未読了(0 件)
        $enrollment = Enrollment::factory()->learning()->create();
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter1 = Chapter::factory()->published()->forPart($part)->create();
        $chapter2 = Chapter::factory()->published()->forPart($part)->create();
        $section1 = Section::factory()->published()->forChapter($chapter1)->create();
        Section::factory()->published()->forChapter($chapter1)->create();
        Section::factory()->published()->forChapter($chapter2)->create();

        SectionProgress::factory()->forEnrollment($enrollment)->forSection($section1)->create();

        $chapters = Chapter::query()->whereIn('id', [$chapter1->id, $chapter2->id])->get();

        // Act
        $result = app(LearningProgressService::class)->sectionCompletionCountsByChapter($chapters, $enrollment);

        // Assert
        $this->assertSame(1, $result[$chapter1->id]);
        $this->assertArrayNotHasKey($chapter2->id, $result, '読了 0 件の Chapter は結果に含まれないはず');
    }

    /**
     * Part1(Chapter1: Section×2, Chapter2: Section×1) / Part2(Chapter3: Section×1) の
     * 公開済ツリーを構築する。合計: Part 2 / Chapter 3 / Section 4。
     *
     * @return array{0: list<Section>, 1: Certification}
     */
    private function buildCertificationTree(string $certificationId): array
    {
        $certification = Certification::query()->findOrFail($certificationId);

        $part1 = Part::factory()->published()->forCertification($certification)->create();
        $chapter1 = Chapter::factory()->published()->forPart($part1)->create();
        $chapter2 = Chapter::factory()->published()->forPart($part1)->create();
        $part2 = Part::factory()->published()->forCertification($certification)->create();
        $chapter3 = Chapter::factory()->published()->forPart($part2)->create();

        $sections = [
            Section::factory()->published()->forChapter($chapter1)->create(),
            Section::factory()->published()->forChapter($chapter1)->create(),
            Section::factory()->published()->forChapter($chapter2)->create(),
            Section::factory()->published()->forChapter($chapter3)->create(),
        ];

        return [$sections, $certification];
    }
}
