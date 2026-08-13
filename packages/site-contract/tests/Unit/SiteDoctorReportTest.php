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

    public function test_scanner_rejects_aliased_sqlite_and_route_registration_construction(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/AppServiceProvider.php' => <<<'PHP'
                <?php
                use SQLite3 as LocalDatabase;
                $database = new LocalDatabase('/tmp/site.sqlite');
                $r->add('/news', new NewsRepository());
                PHP,
        ]);

        self::assertSame([
            'SITE100_RAW_DATABASE_CONSTRUCTION',
            'SITE104_ROUTE_CONSTRUCTION_BYPASS',
        ], array_column($findings, 'id'));
    }

    public function test_hardcoded_origin_cannot_hide_behind_an_unrelated_variable_name(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/CanonicalLink.php' => <<<'PHP'
                <?php
                $url = 'https://production.example.org';
                PHP,
        ]);

        self::assertSame(['SITE103_HARDCODED_PRODUCTION_ORIGIN'], array_column($findings, 'id'));
    }

    public function test_standard_identifier_and_namespace_urls_are_not_misclassified_as_origins(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/StructuredData.php' => <<<'PHP'
                <?php
                $data = ['@context' => 'https://schema.org', '$schema' => 'https://json-schema.org/draft/2020-12/schema'];
                $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                PHP,
        ]);

        self::assertSame([], $findings);
    }

    public function test_route_words_in_comments_do_not_change_constructor_classification(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/StoreFactory.php' => <<<'PHP'
                <?php
                $store = /* unrelated router migration note */ new CacheStore();
                PHP,
        ]);

        self::assertSame([], $findings);
    }

    public function test_literal_concatenation_cannot_hide_origin_or_runtime_ddl(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/Obfuscated.php' => <<<'PHP'
                <?php
                $url = 'https://' . 'production.example.org';
                $sql = 'CREATE ' . 'TABLE hidden (id INTEGER)';
                PHP,
        ]);

        self::assertSame([
            'SITE101_RUNTIME_DDL',
            'SITE103_HARDCODED_PRODUCTION_ORIGIN',
        ], array_column($findings, 'id'));
    }

    public function test_split_statement_route_construction_remains_detectable(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/AppServiceProvider.php' => <<<'PHP'
                <?php
                $repository = new NewsRepository();
                $r->add('/news', $repository);
                PHP,
        ]);

        self::assertSame(['SITE104_ROUTE_CONSTRUCTION_BYPASS'], array_column($findings, 'id'));
    }

    public function test_documentation_variable_name_cannot_exempt_a_production_origin(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/CanonicalLink.php' => <<<'PHP'
                <?php
                $documentationUrl = 'https://production.example.org';
                PHP,
        ]);

        self::assertSame(['SITE103_HARDCODED_PRODUCTION_ORIGIN'], array_column($findings, 'id'));
    }

    public function test_property_indirection_cannot_hide_route_construction(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/AppServiceProvider.php' => <<<'PHP'
                <?php
                $this->repository = new NewsRepository();
                $router->add('/news', $this->repository);
                PHP,
        ]);

        self::assertSame(['SITE104_ROUTE_CONSTRUCTION_BYPASS'], array_column($findings, 'id'));
    }

    public function test_standard_identifier_key_cannot_exempt_a_bare_production_origin(): void
    {
        $findings = new ArchitectureScanner()->scan([
            'src/StructuredData.php' => <<<'PHP'
                <?php
                $data = ['@context' => 'https://production.example.org'];
                PHP,
        ]);

        self::assertSame(['SITE103_HARDCODED_PRODUCTION_ORIGIN'], array_column($findings, 'id'));
    }
}
