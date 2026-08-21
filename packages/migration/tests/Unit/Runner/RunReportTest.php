<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Runner\RecordError;
use Waaseyaa\Migration\Runner\RunReport;
use Waaseyaa\Migration\Advisory\SaveAdvisoryEvidence;

#[CoversClass(RunReport::class)]
#[CoversClass(RecordError::class)]
final class RunReportTest extends TestCase
{
    #[Test]
    public function summary_line_format_known_total(): void
    {
        $report = $this->makeReport(total: 100, imported: 100);
        self::assertSame(
            'demo: complete (100/100, 0 failed, 0 skipped)',
            $report->summaryLine(),
        );
    }

    #[Test]
    public function summary_line_format_unknown_total(): void
    {
        $report = $this->makeReport(total: -1, imported: 12);
        self::assertSame(
            'demo: complete (12/?, 0 failed, 0 skipped)',
            $report->summaryLine(),
        );
    }

    #[Test]
    public function summary_line_reports_failed_state(): void
    {
        $report = $this->makeReport(total: 10, imported: 7, failed: 3, errors: [
            new RecordError('h', 'CODE', 'msg', RecordError::STAGE_PROCESS),
        ]);
        self::assertSame(
            'demo: failed (10/10, 3 failed, 0 skipped)',
            $report->summaryLine(),
        );
    }

    #[Test]
    public function summary_line_reports_partial_state_for_unfinished_run(): void
    {
        $report = $this->makeReport(total: 100, imported: 50);
        self::assertSame(
            'demo: partial (50/100, 0 failed, 0 skipped)',
            $report->summaryLine(),
        );
    }

    #[Test]
    public function summary_line_reports_aborted_state(): void
    {
        $report = $this->makeReport(total: 10, imported: 5, aborted: true);
        self::assertSame(
            'demo: aborted (5/10, 0 failed, 0 skipped)',
            $report->summaryLine(),
        );
    }

    #[Test]
    public function rejects_negative_counts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeReport(imported: -1);
    }

    #[Test]
    public function rejects_more_errors_than_cap(): void
    {
        $errors = [];
        for ($i = 0; $i <= RunReport::ERROR_CAP; $i++) {
            $errors[] = new RecordError('h', 'CODE', 'msg', RecordError::STAGE_PROCESS);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->makeReport(failed: \count($errors), errors: $errors);
    }

    #[Test]
    public function rejects_more_advisory_warnings_than_cap(): void
    {
        $warning = new SaveAdvisoryEvidence(
            'demo',
            str_repeat('a', 64),
            'DEMO_WARNING',
            'title',
            'warning',
            'Review.',
            str_repeat('b', 64),
        );
        $warnings = array_fill(0, RunReport::WARNING_CAP + 1, $warning);

        $this->expectException(\InvalidArgumentException::class);
        new RunReport(
            migrationId: 'demo',
            runId: '019683d3-1234-7000-8123-456789abcdef',
            total: 0,
            imported: 0,
            skipped: 0,
            failed: 0,
            errors: [],
            startedAt: new \DateTimeImmutable('2026-05-13T12:00:00Z'),
            finishedAt: new \DateTimeImmutable('2026-05-13T12:00:01Z'),
            aborted: false,
            warnings: $warnings,
        );
    }

    /**
     * @param list<RecordError> $errors
     */
    private function makeReport(
        int $total = 0,
        int $imported = 0,
        int $skipped = 0,
        int $failed = 0,
        array $errors = [],
        bool $aborted = false,
    ): RunReport {
        return new RunReport(
            migrationId: 'demo',
            runId: '019683d3-1234-7000-8123-456789abcdef',
            total: $total,
            imported: $imported,
            skipped: $skipped,
            failed: $failed,
            errors: $errors,
            startedAt: new \DateTimeImmutable('2026-05-13T12:00:00Z'),
            finishedAt: new \DateTimeImmutable('2026-05-13T12:00:01Z'),
            aborted: $aborted,
        );
    }
}
