<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationRegistrationEngineTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_regs_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        new SiteInitializationService($this->root)->initialize($this->site());
        file_put_contents($this->root . '/composer.json', "{}\n");
        chmod($this->root . '/composer.json', 0o600);
    }
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function managedRegistrationOnlyUnitsEvolveWithoutDiscardingOtherOwners(): void
    {
        $this->publish($this->plan(['App\\A' => 'first']));
        $before = file_get_contents($this->root . '/composer.json');
        self::assertSame(['App\\A'], $this->providers());
        self::assertSame(0o600, fileperms($this->root . '/composer.json') & 0o777);
        self::assertNull($this->prepare($this->plan(['App\\A' => 'first']))['composerMerge']);
        $group = $this->prepare($this->plan(['App\\A' => 'second']));
        self::assertNull($group['composerMerge']);
        $this->publish($this->plan(['App\\A' => 'second']));
        self::assertSame($before, file_get_contents($this->root . '/composer.json'));
        $this->publish($this->plan(['App\\Z' => null], 'scaffold:other'));
        $this->publish($this->plan(['App\\A' => 'second', 'App\\B' => null]));
        self::assertSame(['App\\A', 'App\\Z', 'App\\B'], $this->providers());
        $this->publish($this->plan(['App\\B' => null]));
        self::assertSame(['App\\Z', 'App\\B'], $this->providers());
        $this->publish($this->plan([]));
        self::assertSame(['App\\Z'], $this->providers());
        self::assertSame(['scaffold:other'], array_column($this->metadata()['units'], 'id'));
        self::assertSame([['fqcn' => 'App\\Z', 'unit' => 'scaffold:other']], $this->metadata()['registrations']);
    }

    #[Test]
    public function rootRegistrationsExistIndependentlyOfNonRootUnits(): void
    {
        $this->publish($this->plan(['App\\Root' => null], 'site'));
        $metadata = $this->metadata();
        self::assertArrayNotHasKey('units', $metadata);
        self::assertSame([['fqcn' => 'App\\Root']], $metadata['registrations']);
        unset($metadata['registrations']);
        self::assertSame($this->site()->artifacts['.waaseyaa/generated.json']->content, CanonicalJson::encode($metadata) . "\n");
        $this->publish($this->plan([], 'site'));
        self::assertArrayNotHasKey('registrations', $this->metadata());
    }

    #[Test]
    public function registrationFreePreparationUsesOneCapturedComposerIdentity(): void
    {
        $raw = "{ \"foreign\": {\"0\":1}, \"number\": 123456789012345678901234567890 }\r\n";
        file_put_contents($this->root . '/composer.json', $raw);
        $prepared = $this->prepare($this->plan([], 'site'));
        self::assertNull($prepared['composerMerge']);
        self::assertSame([], $prepared['prepared']);
        self::assertSame(hash('sha256', $raw), $prepared['evaluation']->projectState->composerJsonSha256);
        self::assertSame($raw, file_get_contents($this->root . '/composer.json'));
    }

    #[Test]
    public function managedMissingEntriesAreRestoredAndUnownedEntriesSurviveRetirement(): void
    {
        $this->publish($this->plan(['App\\A' => null]));
        file_put_contents($this->root . '/composer.json', '{"extra":{"waaseyaa":{"providers":["User\\\\Foreign"]}}}');
        $this->publish($this->plan(['App\\A' => null]));
        self::assertSame(['User\\Foreign', 'App\\A'], $this->providers());
        $this->publish($this->plan([], 'site', retires: ['scaffold:example']));
        self::assertSame(['User\\Foreign'], $this->providers());
        self::assertArrayNotHasKey('units', $this->metadata());
        self::assertArrayNotHasKey('registrations', $this->metadata());
    }

    #[Test]
    public function anUnownedEntryCannotBeAdopted(): void
    {
        file_put_contents($this->root . '/composer.json', '{"extra":{"waaseyaa":{"providers":["App\\\\A"]}}}');
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN012');
        $this->prepare($this->plan(['App\\A' => null]));
    }

    #[Test]
    public function samePlanRetirementCannotReparentARegistration(): void
    {
        $this->publish($this->plan(['App\\A' => null]));
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN012');
        $this->prepare($this->plan(['App\\A' => null], 'scaffold:rival', retires: ['scaffold:example']));
    }

    #[Test]
    public function newSeededRegistrationUnitsRemainClosed(): void
    {
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN007');
        $this->prepare($this->plan(['App\\A' => null], disposition: GenerationUnitDisposition::Seeded));
    }

    #[Test]
    public function unchangedSeededRegistrationsNeverRestoreMissingEntries(): void
    {
        $this->seedFixture();
        file_put_contents($this->root . '/composer.json', "{}\n");
        $prepared = $this->prepare($this->plan(['App\\A' => 'one'], disposition: GenerationUnitDisposition::Seeded));
        self::assertNull($prepared['composerMerge']);
        self::assertSame([], $prepared['prepared']);
        unlink($this->root . '/composer.json');
        self::assertNull($this->prepare($this->plan(['App\\A' => 'one'], disposition: GenerationUnitDisposition::Seeded))['composerMerge']);
    }

    #[Test]
    #[DataProvider('seededChanges')]
    public function seededDeclarationEvolutionRefuses(array $registrations): void
    {
        $this->seedFixture();
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN013');
        $this->prepare($this->plan($registrations, disposition: GenerationUnitDisposition::Seeded));
    }

    public static function seededChanges(): iterable
    {
        yield [[]];
        yield [['App\\A' => 'one', 'App\\B' => null]];
        yield [['App\\A' => 'two']];
    }

    #[Test]
    #[DataProvider('invalidComposer')]
    public function malformedProviderStateRefusesEvenWithoutRegistrationWork(string $raw): void
    {
        file_put_contents($this->root . '/composer.json', $raw);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN014');
        $this->prepare($this->plan([], 'site'));
    }

    public static function invalidComposer(): iterable
    {
        yield ['[]'];
        yield ['{"extra":null}'];
        yield ['{"extra":{"waaseyaa":[]}}'];
        yield ['{"extra":{"waaseyaa":{"providers":{}}}}'];
        yield ['{"extra":{"waaseyaa":{"providers":[""]}}}'];
        yield ['{"extra":{"waaseyaa":{"providers":[1]}}}'];
        yield ['{"extra":{"waaseyaa":{"providers":["A","A"]}}}'];
        yield ['{"extra":{},"extra":{}}'];
        yield ['{"extra":{"waaseyaa":{},"waaseyaa":{}}}'];
        yield ['{"extra":{"waaseyaa":{"providers":[],"providers":[]}}}'];
        yield ['{"extra":'];
    }

    #[Test]
    #[DataProvider('badRosters')]
    public function registrationRosterRefusalsAreDistinct(array $roster, string $code): void
    {
        $metadata = $this->metadata();
        $metadata['registrations'] = $roster;
        $this->writeMetadata($metadata);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage($code);
        new SiteInitializationService($this->root)->readUnitMetadata();
    }

    public static function badRosters(): iterable
    {
        yield [[], 'GEN015'];
        yield 'scalar row' => [['invalid'], 'GEN015'];
        yield 'missing fqcn' => [[['group' => 'admin']], 'GEN015'];
        yield 'non-string fqcn' => [[['fqcn' => 123]], 'GEN015'];
        yield 'non-string unit' => [[['fqcn' => 'A', 'unit' => 123]], 'GEN015'];
        yield 'empty group' => [[['fqcn' => 'A', 'group' => '']], 'GEN015'];
        yield 'empty unit' => [[['fqcn' => 'A', 'unit' => '']], 'GEN015'];
        yield [[['fqcn' => '']], 'GEN015'];
        yield [[['fqcn' => 'A', 'group' => null]], 'GEN015'];
        yield [[['fqcn' => 'A', 'extra' => 'x']], 'GEN015'];
        yield [[['fqcn' => 'B'], ['fqcn' => 'A']], 'GEN015'];
        yield [[['fqcn' => 'A'], ['fqcn' => 'A']], 'GEN012'];
        yield [[['fqcn' => 'A', 'unit' => 'missing']], 'GEN012'];
    }

    #[Test]
    public function missingComposerIsNotInventedButAbsentWithdrawalIsIdempotent(): void
    {
        unlink($this->root . '/composer.json');
        self::assertNull($this->prepare($this->plan([], 'site'))['composerMerge']);
        try {
            $this->prepare($this->plan(['App\\A' => null]));
            self::fail('Expected missing Composer refusal.');
        } catch (GenerationRefusalException $exception) {
            self::assertStringContainsString('GEN014', $exception->getMessage());
        }
        file_put_contents($this->root . '/composer.json', '{}');
        $this->publish($this->plan(['App\\A' => null]));
        unlink($this->root . '/composer.json');
        $this->publish($this->plan([], 'site', retires: ['scaffold:example']));
        self::assertFileDoesNotExist($this->root . '/composer.json');
        self::assertArrayNotHasKey('registrations', $this->metadata());
    }

    #[Test]
    public function composerCannotBecomeAnOwnedArtifact(): void
    {
        $plan = new ArtifactPlan('ExampleCompiler', 1, 'scaffold:bad', GenerationUnitDisposition::Managed, str_repeat('a', 64), [new GeneratedArtifact('composer.json', '{}')]);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN003');
        $this->prepare($plan);
    }

    #[Test]
    #[DataProvider('publishFaults')]
    public function interruptedComposerMergeRecoversOriginalBytesAndModes(string $path): void
    {
        $before = file_get_contents($this->root . '/.waaseyaa/generated.json');
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $actual) use ($path): void {
            if ($stage === 'after-replace' && $actual === $path) {
                throw new \Error('interrupted registration');
            }
        });
        try {
            $this->publish($this->plan(['App\\A' => null]), $faulty);
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted registration', $error->getMessage());
        }
        $service = new SiteInitializationService($this->root);
        try {
            $this->invoke($service, 'recoverIfRequired');
            self::fail('Legacy recovery must refuse the merge kind.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid item', $exception->getMessage());
        }
        self::assertTrue($this->invoke($service, 'recoverIfRequired', true));
        self::assertSame("{}\n", file_get_contents($this->root . '/composer.json'));
        self::assertSame(0o600, fileperms($this->root . '/composer.json') & 0o777);
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
    }

    public static function publishFaults(): iterable
    {
        yield ['composer.json'];
        yield ['.waaseyaa/generated.json'];
    }

    #[Test]
    public function legacyMetadataReaderStillRejectsRegistrations(): void
    {
        $this->publish($this->plan(['App\\Root' => null], 'site'));
        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('unsupported shape');
        new SiteInitializationService($this->root)->initialize($this->site(), true);
    }

    #[Test]
    #[DataProvider('composerAliases')]
    public function composerMustBeAPrivateRegularFile(string $kind): void
    {
        $path = $this->root . '/composer.json';
        if ($kind === 'symlink') {
            rename($path, $this->root . '/original.json');
            symlink($this->root . '/original.json', $path);
        } else {
            link($path, $this->root . '/alias.json');
        }
        $this->expectException(SiteInitializationCollisionException::class);
        $this->prepare($this->plan([], 'site'));
    }

    public static function composerAliases(): iterable
    {
        yield ['symlink'];
        yield ['hardlink'];
    }

    #[Test]
    public function retirementRollsBackRegistrationWithdrawalWithItsDeletedFiles(): void
    {
        $plan = new ArtifactPlan(
            'ExampleCompiler',
            1,
            'scaffold:files',
            GenerationUnitDisposition::Managed,
            str_repeat('a', 64),
            [new GeneratedArtifact('generated/A.php', "<?php // owned\n", 0o755)],
            registrations: [new ComposerProviderRegistration('App\\A')],
        );
        $this->publish($plan);
        $metadata = file_get_contents($this->root . '/.waaseyaa/generated.json');
        $composer = file_get_contents($this->root . '/composer.json');
        $faulty = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'after-remove') {
                throw new \Error('retirement interrupted');
            }
        });
        try {
            $this->publish($this->plan([], 'site', retires: ['scaffold:files']), $faulty);
            self::fail('Expected retirement interruption.');
        } catch (\Error $error) {
            self::assertSame('retirement interrupted', $error->getMessage());
        }
        self::assertSame([], $this->providers(), 'Withdrawal was applied before the injected file-removal failure.');
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        self::assertSame($metadata, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertSame($composer, file_get_contents($this->root . '/composer.json'));
        self::assertSame("<?php // owned\n", file_get_contents($this->root . '/generated/A.php'));
        self::assertSame(0o755, fileperms($this->root . '/generated/A.php') & 0o777);
    }

    #[Test]
    public function foreignComposerSubstitutionDuringRecoveryIsPreserved(): void
    {
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path): void {
            if ($stage === 'after-replace' && $path === 'composer.json') {
                throw new \Error('interrupted');
            }
        });
        try {
            $this->publish($this->plan(['App\\A' => null]), $faulty);
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted', $error->getMessage());
        }
        file_put_contents($this->root . '/composer.json', '{"foreign":"edit"}');
        $this->expectException(SiteInitializationCollisionException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            self::assertSame('{"foreign":"edit"}', file_get_contents($this->root . '/composer.json'));
            self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    #[Test]
    public function registrationMergeRecoveryCanFinishAfterBackupCleanupInterruption(): void
    {
        $before = file_get_contents($this->root . '/.waaseyaa/generated.json');
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path): void {
            if ($stage === 'after-replace' && $path === '.waaseyaa/generated.json') {
                throw new \Error('publish interrupted');
            }
        });
        try {
            $this->publish($this->plan(['App\\A' => null]), $faulty);
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('publish interrupted', $error->getMessage());
        }
        $rollback = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'before-rollback-cleanup') {
                throw new \Error('cleanup interrupted');
            }
        });
        try {
            $this->invoke($rollback, 'recoverIfRequired', true);
            self::fail('Expected cleanup interruption.');
        } catch (\Error $error) {
            self::assertSame('cleanup interrupted', $error->getMessage());
        }
        $journal = json_decode((string) file_get_contents($this->root . '/.waaseyaa/site-init.transaction.json'), true, flags: JSON_THROW_ON_ERROR);
        new Filesystem()->remove([$this->root . '/' . $journal['stage'], $this->root . '/' . $journal['backup']]);
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertSame("{}\n", file_get_contents($this->root . '/composer.json'));
        self::assertSame(0o600, fileperms($this->root . '/composer.json') & 0o777);
    }

    #[Test]
    public function evaluationBindsTheComposerBytesItReadRatherThanALaterDiskVersion(): void
    {
        $path = $this->root . '/composer.json';
        $called = false;
        $service = new SiteInitializationService($this->root, static function (string $stage) use ($path, &$called): void {
            if ($stage === 'after-composer-read') {
                $called = true;
                file_put_contents($path, '{"later":true}');
            }
        });
        $evaluation = $service->evaluate($this->plan([], 'site'));
        self::assertTrue($called);
        self::assertSame('{"later":true}', file_get_contents($path));
        self::assertSame(hash('sha256', "{}\n"), $evaluation->projectState->composerJsonSha256);
    }

    private function seedFixture(): void
    {
        $this->publish($this->plan(['App\\A' => 'one']));
        $metadata = $this->metadata();
        $metadata['units'][0]['disposition'] = 'seeded';
        $this->writeMetadata($metadata);
    }
    private function providers(): array
    {
        return new SiteInitializationService($this->root)->readComposerProviderState()['providers'];
    }
    private function metadata(): array
    {
        return json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    private function writeMetadata(array $metadata): void
    {
        file_put_contents($this->root . '/.waaseyaa/generated.json', CanonicalJson::encode($metadata) . "\n");
    }
    private function plan(array $registrations, string $unit = 'scaffold:example', array $retires = [], GenerationUnitDisposition $disposition = GenerationUnitDisposition::Managed): ArtifactPlan
    {
        $site = $this->site();
        ksort($registrations, SORT_STRING);
        $values = [];
        foreach ($registrations as $fqcn => $group) {
            $values[] = new ComposerProviderRegistration($fqcn, $group);
        }
        return new ArtifactPlan(
            $unit === 'site' ? SiteArtifactRenderer::class : 'ExampleCompiler',
            1,
            $unit,
            $disposition,
            $unit === 'site' ? $site->manifestDigest : str_repeat('a', 64),
            $unit === 'site' ? array_values(array_filter($site->artifacts, static fn(GeneratedArtifact $a): bool => $a->path !== '.waaseyaa/generated.json')) : [],
            retires: $retires,
            registrations: $values,
        );
    }
    private function prepare(ArtifactPlan $plan): array
    {
        return $this->invoke(new SiteInitializationService($this->root), 'prepareUnitPlan', $plan);
    }
    private function publish(ArtifactPlan $plan, ?SiteInitializationService $service = null): void
    {
        $service ??= new SiteInitializationService($this->root);
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($service, 'prepareUnitPlan', $plan);
            if ($prepared['prepared'] !== [] || $prepared['retirements'] !== [] || $prepared['composerMerge'] !== null) {
                $this->invoke($service, 'publish', $prepared['prepared'], $prepared['retirements'], $prepared['composerMerge']);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }
    private function site(string $name = 'Example'): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - {id: page, canonical_route: '/{slug}'}
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

        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse(str_replace('name: Example', 'name: ' . $name, $manifest)));
    }

}
