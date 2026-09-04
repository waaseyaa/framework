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
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteDoctorService::class)]
#[CoversClass(SiteDoctorReport::class)]
final class GenerationRegistrationDoctorTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->roots);
    }

    public function testRootRegistrationsAreVerifiedIndependentlyOfRendererProjection(): void
    {
        $root = $this->fixture([['fqcn' => 'App\\Provider\\Root', 'group' => 'cms']], ['App\\Provider\\Root', 'User\\Provider']);
        $before = file_get_contents($root . '/.waaseyaa/generated.json');
        $composer = file_get_contents($root . '/composer.json');
        self::assertTrue(new SiteDoctorService()->inspectUnits($root)->passed);
        self::assertFalse(new SiteDoctorService()->inspect($root)->passed);
        self::assertSame($before, file_get_contents($root . '/.waaseyaa/generated.json'));
        self::assertSame($composer, file_get_contents($root . '/composer.json'));
    }

    public function testMissingManagedAndSeededRegistrationsHaveDistinctDispositionFindings(): void
    {
        $root = $this->fixture([
            ['fqcn' => 'App\\Provider\\Managed', 'unit' => 'scaffold:managed'],
            ['fqcn' => 'App\\Provider\\Seeded', 'unit' => 'scaffold:seeded'],
        ]);
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertFalse($report->passed);
        $findings = array_column($report->findings, null, 'id');
        self::assertCount(2, $findings);
        self::assertSame(FindingSeverity::Error, $findings['SITE015_REGISTRATION_DRIFT']->severity);
        self::assertStringContainsString('scaffold:managed; disposition managed', $findings['SITE015_REGISTRATION_DRIFT']->message);
        self::assertSame(FindingSeverity::Warning, $findings['SITE016_SEEDED_REGISTRATION_MISSING']->severity);
        self::assertStringContainsString('scaffold:seeded; disposition seeded', $findings['SITE016_SEEDED_REGISTRATION_MISSING']->message);
        self::assertStringContainsString('App\\Provider\\Seeded', $findings['SITE016_SEEDED_REGISTRATION_MISSING']->message);
    }

    public function testMissingSeededRegistrationIsVisibleAndNonblocking(): void
    {
        $root = $this->fixture([['fqcn' => 'App\\Provider\\Seeded', 'unit' => 'scaffold:seeded']]);
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertSame(0, $report->exitCode());
        self::assertSame('PASSED_WITH_NOTICES', $report->summary);
        self::assertSame(['SITE016_SEEDED_REGISTRATION_MISSING'], array_column($report->findings, 'id'));
        self::assertSame([], json_decode((string) file_get_contents($root . '/composer.json'), true)['extra']['waaseyaa']['providers']);
    }

    public function testUnownedProvidersDoNotCreateDrift(): void
    {
        $root = $this->fixture([], ['User\\Provider']);
        $doctor = new SiteDoctorService();
        self::assertSame($doctor->inspect($root)->canonicalJson(), $doctor->inspectUnits($root)->canonicalJson());
        self::assertTrue($doctor->inspectUnits($root)->passed);
    }

    public function testGroupHasNoComposerRepresentationToCompare(): void
    {
        $root = $this->fixture([['fqcn' => 'App\\Provider\\Root', 'group' => 'changed-metadata-only']], ['App\\Provider\\Root']);
        self::assertSame([], new SiteDoctorService()->inspectUnits($root)->findings);
    }

    #[DataProvider('invalidComposer')]
    public function testMalformedUnclaimedComposerStateBlocks(string $bytes): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/composer.json', $bytes);
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertFalse($report->passed);
        self::assertContains('SITE015_REGISTRATION_DRIFT', array_column($report->findings, 'id'));
        self::assertSame($bytes, file_get_contents($root . '/composer.json'));
    }

    public static function invalidComposer(): iterable
    {
        yield 'object-not-list' => ['{"extra":{"waaseyaa":{"providers":{}}}}'];
        yield 'scalar' => ['{"extra":{"waaseyaa":{"providers":"App"}}}'];
        yield 'non-string' => ['{"extra":{"waaseyaa":{"providers":[1]}}}'];
        yield 'empty' => ['{"extra":{"waaseyaa":{"providers":[""]}}}'];
        yield 'duplicate' => ['{"extra":{"waaseyaa":{"providers":["App","App"]}}}'];
        yield 'duplicate-ancestor' => ['{"extra":{},"extra":{"waaseyaa":{"providers":[]}}}'];
        yield 'invalid-json' => ['{'];
    }

    public function testComposerSymlinkCannotReadForeignProviderState(): void
    {
        $root = $this->fixture();
        $outside = $this->fixture();
        $sentinel = $outside . '/composer.json';
        $before = file_get_contents($sentinel);
        unlink($root . '/composer.json');
        symlink($sentinel, $root . '/composer.json');
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertContains('SITE015_REGISTRATION_DRIFT', array_column($report->findings, 'id'));
        self::assertSame($before, file_get_contents($sentinel));
    }

    public function testDuplicateRosterOwnershipBlocks(): void
    {
        $root = $this->fixture([['fqcn' => 'App\\Provider'], ['fqcn' => 'App\\Provider', 'unit' => 'scaffold:seeded']], ['App\\Provider']);
        $report = new SiteDoctorService()->inspectUnits($root);
        self::assertFalse($report->passed);
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_column($report->findings, 'id'));
    }

    public function testRegistrationNoticeExceptionIsNarrowAndNeverChangesStrictPolicy(): void
    {
        $notice = new SiteDoctorFinding('SITE016_SEEDED_REGISTRATION_MISSING', FindingSeverity::Warning, 'composer.json', 1, 'Missing seed registration.', 'Review provider removal.', str_repeat('a', 64));
        self::assertTrue(SiteDoctorReport::generation(str_repeat('a', 64), str_repeat('b', 64), [$notice])->passed);
        self::assertFalse(SiteDoctorReport::strict(str_repeat('a', 64), str_repeat('b', 64), [$notice])->passed);
        foreach ([['SITE016_SEEDED_REGISTRATION_MISSING', FindingSeverity::Error], ['SITE015_REGISTRATION_DRIFT', FindingSeverity::Warning]] as [$id, $severity]) {
            $finding = new SiteDoctorFinding($id, $severity, 'composer.json', 1, 'Review.', 'Review.', str_repeat('b', 64));
            self::assertFalse(SiteDoctorReport::generation(str_repeat('a', 64), str_repeat('b', 64), [$finding])->passed);
        }
    }

    public function testActualRootRegistrationPublicationPassesDoctor(): void
    {
        $root = $this->fixture();
        $site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse((string) file_get_contents($root . '/.waaseyaa/site.yaml')));
        $artifacts = array_values(array_filter($site->artifacts, static fn($artifact): bool => $artifact->path !== '.waaseyaa/generated.json'));
        $plan = new ArtifactPlan(SiteArtifactRenderer::class, $site->generatorVersion, 'site', GenerationUnitDisposition::Managed, $site->manifestDigest, $artifacts, registrations: [new ComposerProviderRegistration('App\\Provider\\Published', 'cms')]);
        $service = new SiteInitializationService($root);
        $lock = fopen($root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = new \ReflectionMethod($service, 'prepareUnitPlan')->invoke($service, $plan);
            new \ReflectionMethod($service, 'publish')->invoke($service, $prepared['prepared'], $prepared['retirements'], $prepared['composerMerge']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertTrue(new SiteDoctorService()->inspectUnits($root)->passed);
        self::assertSame(['App\\Provider\\Published'], json_decode((string) file_get_contents($root . '/composer.json'), true)['extra']['waaseyaa']['providers']);
    }

    private function fixture(array $registrations = [], array $providers = []): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_registration_doctor_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
        new SiteInitializationService($root)->initialize(new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest)));
        file_put_contents($root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => $providers]]], JSON_THROW_ON_ERROR) . "\n");
        if ($registrations !== []) {
            $path = $root . '/.waaseyaa/generated.json';
            $metadata = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $units = [];
            foreach ($registrations as $row) {
                if (isset($row['unit'])) {
                    $id = $row['unit'];
                    $units[$id] = ['id' => $id, 'disposition' => str_ends_with($id, 'seeded') ? 'seeded' : 'managed', 'generator' => ['fqcn' => 'Example\\Compiler', 'version' => 1], 'input_digest' => str_repeat('a', 64)];
                }
            }
            if ($units !== []) {
                ksort($units, SORT_STRING);
                $metadata['units'] = array_values($units);
            }
            $metadata['registrations'] = $registrations;
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
