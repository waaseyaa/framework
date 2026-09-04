<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Doctor\FindingSeverity;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;
use Waaseyaa\SiteContract\Doctor\SiteDoctorReport;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteDoctorService::class)]
#[CoversClass(SiteDoctorReport::class)]
final class GenerationUnitDoctorTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            (new Filesystem())->remove($root);
        }
    }

    public function testRootOnlyReportRemainsByteIdentical(): void
    {
        $root = $this->fixture(false);
        $doctor = new SiteDoctorService();
        self::assertSame(rtrim((string) file_get_contents(__DIR__ . '/../../Fixtures/Site/root-doctor-report.json'), "\n"), $doctor->inspect($root)->canonicalJson(), 'The pre-activation root-only report stays byte-identical.');
        self::assertSame($doctor->inspect($root)->canonicalJson(), $doctor->inspectUnits($root)->canonicalJson());
    }

    public function testMultiUnitMetadataIsHealthyThroughBothPublicEntrypoints(): void
    {
        $root = $this->fixture();
        $before = file_get_contents($root . '/.waaseyaa/generated.json');
        $doctor = new SiteDoctorService();
        self::assertTrue($doctor->inspectUnits($root)->passed);
        self::assertTrue($doctor->inspect($root)->passed);
        self::assertSame($doctor->inspect($root)->canonicalJson(), $doctor->inspectUnits($root)->canonicalJson());
        self::assertSame($before, file_get_contents($root . '/.waaseyaa/generated.json'));
    }

    public function testModifiedSeedIsVisibleAndNonblocking(): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/src/Example.php', '<?php // developer edit');
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertSame(0, $report->exitCode());
        self::assertCount(1, $report->findings);
        self::assertSame('SITE013_SEEDED_ARTIFACT_MODIFIED', $report->findings[0]->id);
        self::assertStringContainsString('scaffold:example', $report->findings[0]->message);
        self::assertStringContainsString('seeded', $report->findings[0]->message);
    }

    public function testUnitPathTraversingASymlinkIsRejectedBeforeInspection(): void
    {
        $root = $this->fixture();
        $outside = $this->fixture(false);
        $sentinel = $outside . '/sentinel.txt';
        file_put_contents($sentinel, 'outside');
        symlink($outside, $root . '/src/link');

        $metadataPath = $root . '/.waaseyaa/generated.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($metadata['artifacts'] as &$row) {
            if (($row['unit'] ?? null) === 'scaffold:example') {
                $row['path'] = 'src/link/Example.php';
            }
        }
        unset($row);
        usort($metadata['artifacts'], static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        file_put_contents($metadataPath, CanonicalJson::encode($metadata) . "\n");

        $report = new SiteDoctorService()->inspectUnits($root);

        self::assertSame(1, $report->exitCode());
        self::assertSame(['SITE010_GENERATED_ARTIFACT_DRIFT'], array_column($report->findings, 'id'));
        self::assertSame('.waaseyaa/generated.json', $report->findings[0]->path);
        self::assertSame('outside', file_get_contents($sentinel));
    }

    public function testUnitPathWithASecondHardLinkIsRejected(): void
    {
        $root = $this->fixture();
        link($root . '/src/Example.php', $root . '/src/Example-alias.php');

        $report = new SiteDoctorService()->inspectUnits($root);

        self::assertSame(1, $report->exitCode());
        self::assertSame(['SITE010_GENERATED_ARTIFACT_DRIFT'], array_column($report->findings, 'id'));
        self::assertSame('.waaseyaa/generated.json', $report->findings[0]->path);
    }

    public function testDuplicateUnitArtifactOwnershipIsRejected(): void
    {
        $root = $this->fixture();
        $metadataPath = $root . '/.waaseyaa/generated.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($metadata['artifacts'] as $row) {
            if (($row['unit'] ?? null) === 'scaffold:example') {
                $metadata['artifacts'][] = $row;
                break;
            }
        }
        file_put_contents($metadataPath, CanonicalJson::encode($metadata) . "\n");

        $report = new SiteDoctorService()->inspectUnits($root);

        self::assertSame(1, $report->exitCode());
        self::assertSame(['SITE010_GENERATED_ARTIFACT_DRIFT'], array_column($report->findings, 'id'));
        self::assertSame('.waaseyaa/generated.json', $report->findings[0]->path);
    }

    public function testExtensionRegionEditRemainsHealthyButOutsideEditBlocks(): void
    {
        $root = $this->fixture();
        $agentsPath = $root . '/AGENTS.md';
        $agents = (string) file_get_contents($agentsPath);
        $marker = "<!-- waaseyaa:extension:start local-guidance -->\n<!-- waaseyaa:extension:end local-guidance -->";
        self::assertSame(1, substr_count($agents, $marker));
        $agents = str_replace(
            $marker,
            "<!-- waaseyaa:extension:start local-guidance -->\nLocal guidance.\n<!-- waaseyaa:extension:end local-guidance -->",
            $agents,
        );
        file_put_contents($agentsPath, $agents);

        self::assertTrue(new SiteDoctorService()->inspectUnits($root)->passed);

        file_put_contents($agentsPath, str_replace('# Site contract', '# Substituted contract', $agents));
        $report = new SiteDoctorService()->inspectUnits($root);

        self::assertSame(1, $report->exitCode());
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_column($report->findings, 'id'));
    }

    #[DataProvider('blockingChanges')]
    public function testUnitDriftAndMalformedOwnershipBlock(string $change): void
    {
        $root = $this->fixture();
        $metadataPath = $root . '/.waaseyaa/generated.json';
        $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        if ($change === 'missing') {
            unlink($root . '/src/Example.php');
        } elseif ($change === 'mode') {
            chmod($root . '/src/Example.php', 0o755);
        } elseif ($change === 'managed') {
            $metadata['units'][0]['disposition'] = 'managed';
            file_put_contents($root . '/src/Example.php', '<?php // substituted');
        } elseif ($change === 'root') {
            $metadata['artifacts'][0]['managed_sha256'] = str_repeat('b', 64);
        } elseif ($change === 'unknown-unit') {
            foreach ($metadata['artifacts'] as &$row) {
                if (isset($row['unit'])) {
                    $row['unit'] = 'unknown';
                }
            }
            unset($row);
        } elseif ($change === 'registrations') {
            $metadata['registrations'] = [];
        }
        file_put_contents($metadataPath, CanonicalJson::encode($metadata) . "\n");
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertSame(1, $report->exitCode());
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_column($report->findings, 'id'));
    }

    public static function blockingChanges(): array
    {
        return array_map(static fn(string $case): array => [$case], ['missing', 'mode', 'managed', 'root', 'unknown-unit', 'registrations']);
    }

    public function testNonblockingExceptionNeverGeneralizesToOtherWarningsOrSeedErrors(): void
    {
        foreach ([['SITE199_REVIEW_REQUIRED', FindingSeverity::Warning], ['SITE013_SEEDED_ARTIFACT_MODIFIED', FindingSeverity::Error]] as [$id, $severity]) {
            $finding = new SiteDoctorFinding($id, $severity, 'src/Example.php', 1, 'Review required.', 'Review the file.', str_repeat('a', 64));
            self::assertFalse(SiteDoctorReport::generation(str_repeat('a', 64), str_repeat('b', 64), [$finding])->passed);
        }
        $notice = new SiteDoctorFinding('SITE013_SEEDED_ARTIFACT_MODIFIED', FindingSeverity::Warning, 'src/Example.php', 1, 'Seed modified.', 'Review changes.', str_repeat('a', 64));
        self::assertFalse(SiteDoctorReport::strict(str_repeat('a', 64), str_repeat('b', 64), [$notice])->passed);
        self::assertTrue(SiteDoctorReport::generation(str_repeat('a', 64), str_repeat('b', 64), [$notice])->passed);
    }

    private function fixture(bool $withUnit = true): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_unit_doctor_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700, true);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
        new SiteInitializationService($root)->initialize($site);
        if ($withUnit) {
            mkdir($root . '/src', 0o755);
            file_put_contents($root . '/src/Example.php', '<?php // generated seed');
            chmod($root . '/src/Example.php', 0o644);
            $path = $root . '/.waaseyaa/generated.json';
            $metadata = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $metadata['units'] = [['id' => 'scaffold:example', 'disposition' => 'seeded', 'generator' => ['fqcn' => 'Example\\Compiler', 'version' => 1], 'input_digest' => str_repeat('a', 64)]];
            $metadata['artifacts'][] = ['path' => 'src/Example.php', 'mode' => '0644', 'managed_sha256' => hash_file('sha256', $root . '/src/Example.php'), 'unit' => 'scaffold:example'];
            usort($metadata['artifacts'], static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
            file_put_contents($path, CanonicalJson::encode($metadata) . "\n");
        }
        return $root;
    }

    private function manifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application: {id: example, name: Example, canonical_origin: {config_key: APP_ORIGIN}}
            framework: {revision_policy: exact-lock, observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}
            content_types: [{id: page, canonical_route: '/{slug}'}]
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;
    }
}
