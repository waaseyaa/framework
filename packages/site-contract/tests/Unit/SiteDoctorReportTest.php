<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Doctor\ArchitectureScanner;
use Waaseyaa\SiteContract\Doctor\FindingSeverity;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;
use Waaseyaa\SiteContract\Doctor\SiteDoctorReport;

final class SiteDoctorReportTest extends TestCase
{
    public function test_strict_report_is_canonical_and_never_calls_warnings_ok(): void
    {
        $warning = new SiteDoctorFinding(
            'SITE199_REVIEW_REQUIRED',
            FindingSeverity::Warning,
            'config/site.php',
            12,
            'A declared boundary still requires review.',
            'Classify the boundary explicitly.',
            hash('sha256', 'config/site.php:12'),
        );
        $error = new SiteDoctorFinding(
            'SITE100_RAW_DATABASE_CONSTRUCTION',
            FindingSeverity::Error,
            'src/SubscriberStore.php',
            8,
            'Application code constructs PDO directly.',
            'Use the framework-managed database authority.',
            hash('sha256', 'src/SubscriberStore.php:8'),
        );

        $report = SiteDoctorReport::strict(
            str_repeat('a', 64),
            str_repeat('b', 64),
            [$warning, $error],
        );

        self::assertFalse($report->passed);
        self::assertSame('FAILED', $report->summary);
        self::assertSame(1, $report->exitCode());
        self::assertSame(
            ['SITE199_REVIEW_REQUIRED', 'SITE100_RAW_DATABASE_CONSTRUCTION'],
            array_column($report->toArray()['findings'], 'id'),
        );
        self::assertSame($report->canonicalJson(), SiteDoctorReport::strict(
            str_repeat('a', 64),
            str_repeat('b', 64),
            [$warning, $error],
        )->canonicalJson());
    }

    public function test_architecture_scanner_finds_each_prohibited_occurrence_with_exact_evidence(): void
    {
        $source = <<<'PHP'
            <?php
            $pdo = new \PDO('sqlite:/tmp/site.sqlite');
            $pdo->exec('CREATE TABLE subscriber (email TEXT)');
            $origin = 'https://production.example.org';
            $canonical = $_SERVER['HTTP_HOST'].'/updates/'.$slug;
            PHP;

        $findings = new ArchitectureScanner()->scan([
            'src/SubscriberStore.php' => $source,
        ]);

        self::assertSame([
            'SITE100_RAW_DATABASE_CONSTRUCTION',
            'SITE101_RUNTIME_DDL',
            'SITE102_SERVER_CANONICAL_ORIGIN',
            'SITE103_HARDCODED_PRODUCTION_ORIGIN',
        ], array_column($findings, 'id'));
        self::assertSame([2, 3, 5, 4], array_column($findings, 'line'));
        foreach ($findings as $finding) {
            self::assertSame('src/SubscriberStore.php', $finding->path);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $finding->evidenceDigest);
        }
    }

    public function test_scanner_does_not_misclassify_unrelated_urls_or_server_metadata(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/Telemetry.php' => <<<'PHP'
                <?php
                $documentation = 'https://docs.example.org/operator-guide';
                $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
                PHP,
        ]);

        self::assertSame([], $findings);
    }
}
