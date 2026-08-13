<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Doctor\DoctorSuppressionSet;
use Waaseyaa\SiteContract\Doctor\FindingSeverity;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;

final class DoctorSuppressionSetTest extends TestCase
{
    public function testOnlyAnExactUnexpiredFindingAndSourceIdentityIsSuppressed(): void
    {
        $finding = $this->finding();
        $sourceDigest = str_repeat('b', 64);
        $yaml = sprintf(<<<'YAML'
            schema: waaseyaa.site-doctor-suppressions
            version: 1
            suppressions:
              - finding_id: SITE100_RAW_DATABASE_CONSTRUCTION
                path: src/Legacy.php
                line: 8
                evidence_sha256: %s
                source_sha256: %s
                reason: Migration to the framework database authority is tracked in issue 42.
                expires: '2026-09-01'
            YAML, $finding->evidenceDigest, $sourceDigest);

        $result = new DoctorSuppressionSet()->apply($yaml, [$finding], $sourceDigest, new \DateTimeImmutable('2026-08-13'));

        self::assertSame([], $result->findings);
        self::assertSame(['SITE100_RAW_DATABASE_CONSTRUCTION'], array_column($result->suppressed, 'id'));
    }

    public function testExpiredUnusedOrSubstitutedSuppressionsFailClosed(): void
    {
        $finding = $this->finding();
        $sourceDigest = str_repeat('b', 64);
        $template = <<<'YAML'
            schema: waaseyaa.site-doctor-suppressions
            version: 1
            suppressions:
              - finding_id: SITE100_RAW_DATABASE_CONSTRUCTION
                path: src/Legacy.php
                line: 8
                evidence_sha256: %s
                source_sha256: %s
                reason: Temporary reviewed exception.
                expires: '%s'
            YAML;

        $expired = new DoctorSuppressionSet()->apply(sprintf($template, $finding->evidenceDigest, $sourceDigest, '2026-08-12'), [$finding], $sourceDigest, new \DateTimeImmutable('2026-08-13'));
        self::assertContains('SITE900_INVALID_SUPPRESSION', array_column($expired->findings, 'id'));
        $unused = new DoctorSuppressionSet()->apply(sprintf($template, str_repeat('c', 64), $sourceDigest, '2026-09-01'), [$finding], $sourceDigest, new \DateTimeImmutable('2026-08-13'));
        self::assertContains('SITE901_UNUSED_SUPPRESSION', array_column($unused->findings, 'id'));
        self::assertContains('SITE100_RAW_DATABASE_CONSTRUCTION', array_column($unused->findings, 'id'));
        $wrongSource = new DoctorSuppressionSet()->apply(sprintf($template, $finding->evidenceDigest, str_repeat('d', 64), '2026-09-01'), [$finding], $sourceDigest, new \DateTimeImmutable('2026-08-13'));
        self::assertContains('SITE900_INVALID_SUPPRESSION', array_column($wrongSource->findings, 'id'));
    }

    private function finding(): SiteDoctorFinding
    {
        return new SiteDoctorFinding(
            'SITE100_RAW_DATABASE_CONSTRUCTION',
            FindingSeverity::Error,
            'src/Legacy.php',
            8,
            'Application code constructs PDO directly.',
            'Use the framework-managed database authority.',
            hash('sha256', "src/Legacy.php\08\0new PDO"),
        );
    }
}
