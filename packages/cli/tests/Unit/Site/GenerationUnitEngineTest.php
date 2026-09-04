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
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationUnitEngineTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_units_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        new SiteInitializationService($this->root)->initialize($this->site());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function itCarriesIndependentRowsWithoutReadingTheirContent(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $before = $this->metadata();
        file_put_contents($this->root . '/src/Example.php', 'developer edit');
        $prepared = $this->invoke($service, 'prepareUnitPlan', $this->rootPlan());
        self::assertSame([], $prepared['prepared']);
        self::assertSame($before, $this->metadata());
        self::assertSame(['scaffold:example'], array_column($before['units'], 'id'));
        self::assertNotContains('src/Example.php', array_column($prepared['evaluation']->projectState->toArray()['targets'], 'path'));
    }

    #[Test]
    public function legacyReaderStillRefusesFutureMetadata(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('Generated ownership metadata has an unsupported shape.');
        $service->initialize($this->site(), true);
    }

    #[Test]
    public function firstOwnerCannotBeCapturedEvenWhenRetiredInTheSamePlan(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN003');
        $service->evaluate($this->plan('scaffold:rival', ['scaffold:example']));
    }

    #[Test]
    public function emptySeededAllowlistRefusesNewSeededPlans(): void
    {
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN007');
        new SiteInitializationService($this->root)->evaluate($this->plan(disposition: GenerationUnitDisposition::Seeded));
    }

    #[Test]
    public function persistedSeededRowsRemainReadableWithoutCompilerAdmission(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $metadata = $this->metadata();
        $metadata['units'][0]['disposition'] = 'seeded';
        $this->writeMetadata($metadata);
        file_put_contents($this->root . '/src/Example.php', 'edited seed');
        self::assertSame([], $this->invoke($service, 'prepareUnitPlan', $this->rootPlan())['prepared']);
    }

    #[Test]
    public function retirementRestoresLegacyMetadataBytes(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $document = $this->metadata();
        unset($document['units']);
        $document['artifacts'] = array_values(array_filter($document['artifacts'], static fn(array $row): bool => !isset($row['unit'])));
        self::assertSame($this->site()->artifacts['.waaseyaa/generated.json']->content, CanonicalJson::encode($document) . "\n");
        $this->publish($service, $this->rootPlan(['scaffold:example']));
        self::assertFileDoesNotExist($this->root . '/src/Example.php');
        self::assertDirectoryDoesNotExist($this->root . '/src');
        self::assertSame($this->site()->artifacts['.waaseyaa/generated.json']->content, file_get_contents($this->root . '/.waaseyaa/generated.json'));
    }

    #[Test]
    #[DataProvider('faults')]
    public function interruptedRetirementRestoresBytesModesAndDirectories(string $stage): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        chmod($this->root . '/src', 0o710);
        $before = file_get_contents($this->root . '/.waaseyaa/generated.json');
        $faulty = new SiteInitializationService($this->root, static function (string $at) use ($stage): void {
            if ($at === $stage) {
                throw new \Error('interrupted retirement');
            }
        });
        try {
            $this->publish($faulty, $this->rootPlan(['scaffold:example']));
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted retirement', $error->getMessage());
        }
        self::assertTrue($this->invoke($service, 'recoverIfRequired', true));
        clearstatcache();
        self::assertSame("<?php // original\n", file_get_contents($this->root . '/src/Example.php'));
        self::assertSame(0o755, fileperms($this->root . '/src/Example.php') & 0o777);
        self::assertSame(0o710, fileperms($this->root . '/src') & 0o777);
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
    }

    public static function faults(): iterable
    {
        yield ['before-remove'];
        yield ['after-remove'];
        yield ['after-remove-directory'];
    }

    #[Test]
    #[DataProvider('reservedPaths')]
    public function aPlanCannotOwnTransactionControlState(string $path): void
    {
        $plan = new ArtifactPlan('ExampleCompiler', 1, 'scaffold:hostile', GenerationUnitDisposition::Managed, str_repeat('b', 64), [new GeneratedArtifact($path, "hostile\n")]);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN003');
        new SiteInitializationService($this->root)->evaluate($plan);
    }

    #[Test]
    #[DataProvider('reservedPaths')]
    public function metadataCannotClaimTransactionControlState(string $path): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $metadata = $this->metadata();
        foreach ($metadata['artifacts'] as &$row) {
            if (isset($row['unit'])) {
                $row['path'] = $path;
            }
        }
        unset($row);
        usort($metadata['artifacts'], static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $this->writeMetadata($metadata);
        $this->expectException(GenerationRefusalException::class);
        $service->readUnitMetadata();
    }

    public static function reservedPaths(): iterable
    {
        yield ['.waaseyaa/generated.json'];
        yield ['.waaseyaa/site-init.lock'];
        yield ['.waaseyaa/site-init.transaction.json'];
        yield ['.waaseyaa/site-init-stage-aaaaaaaaaaaaaaaaaaaaaaaa/0000.artifact'];
        yield ['.waaseyaa/site-init-backup-aaaaaaaaaaaaaaaaaaaaaaaa/0000.backup'];
    }

    #[Test]
    #[DataProvider('malformedRosters')]
    public function malformedUnitOwnershipRefuses(string $mutation, string $code): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $metadata = $this->metadata();
        if ($mutation === 'duplicate') {
            $metadata['units'][] = $metadata['units'][0];
        } elseif ($mutation === 'unknown') {
            foreach ($metadata['artifacts'] as &$row) {
                if (isset($row['unit'])) {
                    $row['unit'] = 'missing';
                }
            }
            unset($row);
        } elseif ($mutation === 'reserved') {
            $metadata['units'][0]['id'] = 'site';
        } elseif ($mutation === 'bad-digest') {
            $metadata['artifacts'][0]['managed_sha256'] = [];
        } elseif ($mutation === 'empty') {
            $metadata['units'] = [];
        } else {
            $metadata['units'][0]['id'] = '../escape';
        }
        $this->writeMetadata($metadata);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage($code);
        $service->evaluate($this->rootPlan());
    }

    public static function malformedRosters(): iterable
    {
        yield ['duplicate', 'GEN010'];
        yield ['unknown', 'GEN010'];
        yield ['reserved', 'GEN010'];
        yield ['grammar', 'GEN006'];
        yield ['bad-digest', 'GEN010'];
        yield ['empty', 'GEN010'];
    }

    #[Test]
    public function registrationStateStillFailsClosed(): void
    {
        $metadata = $this->metadata();
        $metadata['registrations'] = [['fqcn' => 'ExampleProvider']];
        $this->writeMetadata($metadata);
        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('unsupported shape');
        new SiteInitializationService($this->root)->readUnitMetadata();
    }

    #[Test]
    #[DataProvider('retirementDrift')]
    public function retirementCannotDestroyChangedOrAliasedFiles(string $mutation): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $path = $this->root . '/src/Example.php';
        if ($mutation === 'content') {
            file_put_contents($path, '<?php // edit');
        } elseif ($mutation === 'mode') {
            chmod($path, 0o644);
        } elseif ($mutation === 'hardlink') {
            link($path, $this->root . '/alias.php');
        } else {
            rename($path, $this->root . '/original.php');
            symlink($this->root . '/original.php', $path);
        }
        $this->expectException(\RuntimeException::class);
        try {
            $service->evaluate($this->rootPlan(['scaffold:example']));
        } finally {
            self::assertFileExists($path);
            self::assertCount(1, $this->metadata()['units']);
            self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    public static function retirementDrift(): iterable
    {
        yield ['content'];
        yield ['mode'];
        yield ['hardlink'];
        yield ['symlink'];
    }

    #[Test]
    public function legacyRecoveryStillRefusesTheNewJournalShape(): void
    {
        $this->interruptRetirement();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('journal is invalid');
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired');
    }

    #[Test]
    public function retirementRecoveryRefusesReappearedForeignBytes(): void
    {
        $this->interruptRetirement();
        file_put_contents($this->root . '/src/Example.php', 'foreign');
        $this->expectException(SiteInitializationCollisionException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            self::assertSame('foreign', file_get_contents($this->root . '/src/Example.php'));
            self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    #[Test]
    public function interruptedRollbackCanBeRetriedWithoutLosingItsBackup(): void
    {
        $before = $this->interruptRetirement();
        $faulty = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'after-rollback-copy') {
                throw new \Error('interrupted restore');
            }
        });
        try {
            $this->invoke($faulty, 'recoverIfRequired', true);
            self::fail('Expected interrupted recovery.');
        } catch (\Error $error) {
            self::assertSame('interrupted restore', $error->getMessage());
        }
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertSame("<?php // original\n", file_get_contents($this->root . '/src/Example.php'));
    }

    #[Test]
    public function substitutedBackupRefusesRecovery(): void
    {
        $this->interruptRetirement();
        $journal = json_decode((string) file_get_contents($this->root . '/.waaseyaa/site-init.transaction.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) === 'remove') {
                file_put_contents($this->root . '/' . $item['backup'], 'substituted');
            }
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backup was substituted');
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
    }

    #[Test]
    public function committedRetirementCleanupDoesNotRestoreRetiredFiles(): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $faulty = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'after-commit') {
                throw new \RuntimeException('cleanup interrupted');
            }
        });
        $this->publish($faulty, $this->rootPlan(['scaffold:example']));
        self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        $this->invoke($service, 'recoverIfRequired', true);
        self::assertFileDoesNotExist($this->root . '/src/Example.php');
        self::assertArrayNotHasKey('units', $this->metadata());
    }

    private function interruptRetirement(string $faultStage = 'after-remove'): string
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $before = (string) file_get_contents($this->root . '/.waaseyaa/generated.json');
        $faulty = new SiteInitializationService($this->root, static function (string $stage) use ($faultStage): void {
            if ($stage === $faultStage) {
                throw new \Error('interrupted retirement');
            }
        });
        try {
            $this->publish($faulty, $this->rootPlan(['scaffold:example']));
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted retirement', $error->getMessage());
        }

        return $before;
    }

    #[Test]
    #[DataProvider('aliasPaths')]
    public function aliasesCannotBypassUnitOwnershipOrControlGuards(string $path): void
    {
        $plan = new ArtifactPlan('ExampleCompiler', 1, 'scaffold:alias', GenerationUnitDisposition::Managed, str_repeat('b', 64), [new GeneratedArtifact($path, 'alias')]);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN001');
        new SiteInitializationService($this->root)->evaluate($plan);
    }

    public static function aliasPaths(): iterable
    {
        yield ['src/./Example.php'];
        yield ['src//Example.php'];
        yield ['.waaseyaa/./site-init.lock'];
        yield ['.waaseyaa//site-init.transaction.json'];
    }

    #[Test]
    public function aNewNonRootUnitMustOwnState(): void
    {
        $plan = new ArtifactPlan('ExampleCompiler', 1, 'scaffold:empty', GenerationUnitDisposition::Managed, str_repeat('b', 64), []);
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage('GEN007');
        new SiteInitializationService($this->root)->evaluate($plan);
    }

    #[Test]
    public function aPendingRemovalCannotForgetAnAlreadyMissingTarget(): void
    {
        $this->interruptRetirement();
        $journalPath = $this->root . '/.waaseyaa/site-init.transaction.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, flags: JSON_THROW_ON_ERROR);
        $backup = '';
        foreach ($journal['items'] as &$item) {
            if (($item['kind'] ?? null) === 'remove') {
                $item['state'] = 'pending';
                $backup = $this->root . '/' . $item['backup'];
            }
        }
        unset($item);
        file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR));
        $this->expectException(SiteInitializationCollisionException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            self::assertFileExists($journalPath);
            self::assertFileExists($backup);
            self::assertFileDoesNotExist($this->root . '/src/Example.php');
        }
    }

    #[Test]
    public function aReappearedRemovalTargetWithDifferentModeIsNotOwnedRecovery(): void
    {
        $this->interruptRetirement();
        $path = $this->root . '/src/Example.php';
        file_put_contents($path, "<?php // original\n");
        chmod($path, 0o644);
        $this->expectException(SiteInitializationCollisionException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            clearstatcache();
            self::assertSame(0o644, fileperms($path) & 0o777);
            self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    #[Test]
    public function aReappearedRemovedDirectoryCannotImportForeignContentsIntoRecovery(): void
    {
        $this->interruptRetirement('after-remove-directory');
        mkdir($this->root . '/src', 0o755);
        chmod($this->root . '/src', 0o755);
        file_put_contents($this->root . '/src/sentinel.txt', 'foreign');
        $this->expectException(SiteInitializationCollisionException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            self::assertSame('foreign', file_get_contents($this->root . '/src/sentinel.txt'));
            self::assertFileDoesNotExist($this->root . '/src/Example.php');
            self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    #[Test]
    #[DataProvider('restorationFaults')]
    public function recoveryAcceptsItsOwnAlreadyRestoredDirectoriesAndFiles(string $faultStage): void
    {
        $before = $this->interruptRetirement('after-remove-directory');
        $faulty = new SiteInitializationService($this->root, static function (string $stage) use ($faultStage): void {
            if ($stage === $faultStage) {
                throw new \Error('interrupted restoration');
            }
        });
        try {
            $this->invoke($faulty, 'recoverIfRequired', true);
            self::fail('Expected restoration interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted restoration', $error->getMessage());
        }
        $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertSame("<?php // original\n", file_get_contents($this->root . '/src/Example.php'));
    }

    public static function restorationFaults(): iterable
    {
        yield ['after-rollback-directory'];
        yield ['after-rollback-restore'];
    }

    #[Test]
    public function completedRollbackCanFinishCleanupAfterItsBackupsWereRemoved(): void
    {
        $before = $this->interruptAfterRollbackBeforeCleanup();
        $service = new SiteInitializationService($this->root);
        self::assertTrue($this->invoke($service, 'recoverIfRequired', true));
        self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertSame("<?php // original\n", file_get_contents($this->root . '/src/Example.php'));
        self::assertFileDoesNotExist($this->root . '/.waaseyaa/site-init.transaction.json');
    }

    #[Test]
    #[DataProvider('cleanupDrifts')]
    public function cleanupWithoutBackupsRequiresTheEntirePriorState(string $mutation): void
    {
        $this->interruptAfterRollbackBeforeCleanup();
        if ($mutation === 'root-content') {
            file_put_contents($this->root . '/.waaseyaa/generated.json', "{}\n");
        } elseif ($mutation === 'removed-mode') {
            chmod($this->root . '/src/Example.php', 0o644);
        } elseif ($mutation === 'directory-mode') {
            chmod($this->root . '/src', 0o700);
        } elseif ($mutation === 'foreign-child') {
            file_put_contents($this->root . '/src/foreign.txt', 'foreign');
        } else {
            unlink($this->root . '/src/Example.php');
        }
        $this->expectException(\RuntimeException::class);
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } finally {
            self::assertFileExists($this->root . '/.waaseyaa/site-init.transaction.json');
        }
    }

    public static function cleanupDrifts(): iterable
    {
        yield ['root-content'];
        yield ['removed-mode'];
        yield ['directory-mode'];
        yield ['foreign-child'];
        yield ['removed-absent'];
    }

    private function interruptAfterRollbackBeforeCleanup(): string
    {
        $before = $this->interruptRetirement('after-remove-directory');
        $service = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'after-rollback-restore') {
                throw new \Error('restored before cleanup');
            }
        });
        try {
            $this->invoke($service, 'recoverIfRequired', true);
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('restored before cleanup', $error->getMessage());
        }
        $journal = json_decode((string) file_get_contents($this->root . '/.waaseyaa/site-init.transaction.json'), true, flags: JSON_THROW_ON_ERROR);
        // Model process death during the final cleanup: the exact prior tree
        // is restored, but recovery evidence removal preceded journal unlink.
        new Filesystem()->remove([$this->root . '/' . $journal['stage'], $this->root . '/' . $journal['backup']]);

        return $before;
    }

    #[Test]
    #[DataProvider('newTargetCleanupStates')]
    public function cleanupProofIncludesOriginallyAbsentFilesAndDirectories(string $reappeared): void
    {
        $service = new SiteInitializationService($this->root);
        $this->publish($service, $this->plan());
        $before = (string) file_get_contents($this->root . '/.waaseyaa/generated.json');
        $replacement = new ArtifactPlan('ExampleCompiler', 1, 'scaffold:replacement', GenerationUnitDisposition::Managed, str_repeat('c', 64), [new GeneratedArtifact('fresh/New.php', "<?php // new\n")], retires: ['scaffold:example']);
        $faulty = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'after-remove-directory') {
                throw new \Error('interrupted mixed transaction');
            }
        });
        try {
            $this->publish($faulty, $replacement);
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted mixed transaction', $error->getMessage());
        }
        $rollback = new SiteInitializationService($this->root, static function (string $stage): void {
            if ($stage === 'before-rollback-cleanup') {
                throw new \Error('rollback complete');
            }
        });
        try {
            $this->invoke($rollback, 'recoverIfRequired', true);
            self::fail('Expected cleanup interruption.');
        } catch (\Error $error) {
            self::assertSame('rollback complete', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/fresh');
        $journalPath = $this->root . '/.waaseyaa/site-init.transaction.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, flags: JSON_THROW_ON_ERROR);
        new Filesystem()->remove([$this->root . '/' . $journal['stage'], $this->root . '/' . $journal['backup']]);
        if ($reappeared !== 'none') {
            mkdir($this->root . '/fresh', 0o755);
            if ($reappeared === 'file') {
                file_put_contents($this->root . '/fresh/New.php', "<?php // new\n");
            }
            $this->expectException(SiteInitializationCollisionException::class);
            try {
                $this->invoke($service, 'recoverIfRequired', true);
            } finally {
                self::assertFileExists($journalPath);
            }
        } else {
            self::assertTrue($this->invoke($service, 'recoverIfRequired', true));
            self::assertSame($before, file_get_contents($this->root . '/.waaseyaa/generated.json'));
            self::assertDirectoryDoesNotExist($this->root . '/fresh');
            self::assertFileDoesNotExist($journalPath);
        }
    }

    public static function newTargetCleanupStates(): iterable
    {
        yield ['none'];
        yield ['file'];
        yield ['directory'];
    }

    private function plan(string $id = 'scaffold:example', array $retires = [], GenerationUnitDisposition $disposition = GenerationUnitDisposition::Managed): ArtifactPlan
    {
        return new ArtifactPlan('ExampleCompiler', 1, $id, $disposition, str_repeat('b', 64), [
            new GeneratedArtifact('src/Example.php', "<?php // original\n", 0o755),
        ], retires: $retires);
    }

    private function rootPlan(array $retires = []): ArtifactPlan
    {
        $site = $this->site();
        return new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            array_values(array_filter($site->artifacts, static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json')),
            retires: $retires,
        );
    }

    private function publish(SiteInitializationService $service, ArtifactPlan $plan): void
    {
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($service, 'prepareUnitPlan', $plan);
            $this->invoke($service, 'publish', $prepared['prepared'], $prepared['retirements']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }

    private function metadata(): array
    {
        return json_decode((string) file_get_contents($this->root . '/.waaseyaa/generated.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function writeMetadata(array $metadata): void
    {
        file_put_contents($this->root . '/.waaseyaa/generated.json', CanonicalJson::encode($metadata) . "\n");
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
