<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Site\SiteInitializationResult;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
#[CoversClass(SiteInitializationResult::class)]
final class SiteInitializationServiceTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function itPublishesAllArtifactsAndARepeatedRunIsByteIdentical(): void
    {
        $root = $this->root();
        $site = $this->site();
        $service = new SiteInitializationService($root);

        $first = $service->initialize($site);
        $before = $this->snapshot($root);
        $second = $service->initialize($site);

        self::assertSame(array_values(array_filter(array_keys($site->artifacts), static fn(string $path): bool => $path !== '.waaseyaa/.gitignore')), $first->changedPaths);
        self::assertSame([], $second->changedPaths);
        self::assertSame($before, $this->snapshot($root));
    }

    #[Test]
    public function itRefusesAnUnownedCollisionBeforeChangingAnyTarget(): void
    {
        $root = $this->root();
        mkdir($root . '/tests/Architecture', 0777, true);
        file_put_contents($root . '/tests/Architecture/SiteContractTest.php', 'owned by the application');
        $before = $this->snapshot($root);

        $this->expectException(SiteInitializationCollisionException::class);
        try {
            new SiteInitializationService($root)->initialize($this->site());
        } finally {
            self::assertSame($before, $this->snapshot($root));
            self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
        }
    }

    #[Test]
    public function itPreservesTheDeclaredExtensionRegionAndRejectsOtherGeneratedEdits(): void
    {
        $root = $this->root();
        $service = new SiteInitializationService($root);
        $service->initialize($this->site());
        $agents = (string) file_get_contents($root . '/AGENTS.md');
        $agents = str_replace(
            "<!-- waaseyaa:extension:start local-guidance -->\n<!-- waaseyaa:extension:end local-guidance -->",
            "<!-- waaseyaa:extension:start local-guidance -->\nUse the Nation's editorial voice.\n<!-- waaseyaa:extension:end local-guidance -->",
            $agents,
        );
        file_put_contents($root . '/AGENTS.md', $agents);

        $service->initialize($this->site());
        self::assertStringContainsString("Use the Nation's editorial voice.", (string) file_get_contents($root . '/AGENTS.md'));

        file_put_contents($root . '/AGENTS.md', str_replace('# Site contract', '# Substituted contract', (string) file_get_contents($root . '/AGENTS.md')));
        $this->expectException(SiteInitializationCollisionException::class);
        $service->initialize($this->site());
    }

    #[Test]
    public function itRollsBackEveryPublishedTargetWhenAnOrdinaryFailureOccurs(): void
    {
        $root = $this->root();
        $before = $this->snapshot($root);
        $fault = static function (string $stage, int $index): void {
            if ($stage === 'after-replace' && $index === 0) {
                throw new \RuntimeException('injected publish failure');
            }
        };

        try {
            new SiteInitializationService($root, $fault)->initialize($this->site());
            self::fail('Expected the injected failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected publish failure', $exception->getMessage());
        }

        self::assertSame(['.waaseyaa/.gitignore'], array_keys($this->snapshot($root)));
    }

    #[Test]
    #[DataProvider('interruptionProvider')]
    public function itRecoversAnInterruptedTransactionBeforeTheNextRun(string $failureStage, int $failureIndex, int $expectedChanges): void
    {
        $root = $this->root();
        $fault = static function (string $stage, int $index) use ($failureStage, $failureIndex): void {
            if ($stage === $failureStage && $index === $failureIndex) {
                throw new \Error('simulated process termination');
            }
        };
        try {
            new SiteInitializationService($root, $fault)->initialize($this->site());
            self::fail('Expected simulated process termination.');
        } catch (\Error) {
        }

        self::assertFileExists($root . '/.waaseyaa/site-init.transaction.json');
        $result = new SiteInitializationService($root)->initialize($this->site());

        self::assertCount($expectedChanges, $result->changedPaths);
        self::assertTrue($result->recoveredInterruptedTransaction);
        self::assertFileDoesNotExist($root . '/.waaseyaa/site-init.transaction.json');
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function interruptionProvider(): iterable
    {
        foreach (range(0, 6) as $index) {
            yield "before replacement {$index}" => ['before-replace', $index, 7];
            yield "after replacement {$index}" => ['after-replace', $index, 7];
        }
        yield 'after durable commit' => ['after-commit', -1, 0];
    }

    #[Test]
    public function dryRunIsStrictlyReadOnly(): void
    {
        $root = $this->root();

        $result = new SiteInitializationService($root)->initialize($this->site(), true);

        self::assertCount(8, $result->changedPaths);
        self::assertTrue($result->dryRun);
        self::assertSame([], $this->snapshot($root));
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function itRejectsSubstitutedOwnershipMetadata(): void
    {
        $root = $this->root();
        $service = new SiteInitializationService($root);
        $service->initialize($this->site());
        $metadataPath = $root . '/.waaseyaa/generated.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $metadata['generator_version'] = 99;
        file_put_contents($metadataPath, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(SiteInitializationCollisionException::class);
        $service->initialize($this->site());
    }

    #[Test]
    public function itRecoversDurablePreJournalResidueBeforeStartingNewWork(): void
    {
        $root = $this->root();
        $fault = static function (string $stage, int $index): void {
            if ($stage === 'after-stage' && $index === 2) {
                throw new \Error('simulated pre-journal termination');
            }
        };
        try {
            new SiteInitializationService($root, $fault)->initialize($this->site());
            self::fail('Expected simulated process termination.');
        } catch (\Error) {
        }

        self::assertFileDoesNotExist($root . '/.waaseyaa/site-init.transaction.json');
        self::assertNotSame([], glob($root . '/.waaseyaa/site-init-stage-*'));

        $result = new SiteInitializationService($root)->initialize($this->site());

        self::assertTrue($result->recoveredInterruptedTransaction);
        self::assertSame([], glob($root . '/.waaseyaa/site-init-stage-*'));
        self::assertSame([], glob($root . '/.waaseyaa/site-init-backup-*'));
    }

    #[Test]
    public function aPostCommitCleanupFailureCannotReinterpretTheCommitAsFailed(): void
    {
        $root = $this->root();
        $fault = static function (string $stage): void {
            if ($stage === 'after-commit') {
                throw new \RuntimeException('simulated cleanup failure');
            }
        };

        $result = new SiteInitializationService($root, $fault)->initialize($this->site());

        self::assertTrue($result->cleanupPending);
        self::assertFileExists($root . '/.waaseyaa/generated.json');
        self::assertFileExists($root . '/.waaseyaa/site-init.transaction.json');

        $recovered = new SiteInitializationService($root)->initialize($this->site());
        self::assertTrue($recovered->recoveredInterruptedTransaction);
        self::assertSame([], $recovered->changedPaths);
        self::assertFalse($recovered->cleanupPending);
    }

    #[Test]
    public function itRejectsARecoveryJournalThatRedirectsItsControlPaths(): void
    {
        $root = $this->root();
        $outside = $this->root();
        $this->interruptAfterFirstReplacement($root);
        $journalPath = $root . '/.waaseyaa/site-init.transaction.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        $journal['stage'] = '../' . basename($outside);
        file_put_contents($journalPath, json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        try {
            new SiteInitializationService($root)->initialize($this->site());
            self::fail('Expected the redirected journal to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('journal is invalid', $exception->getMessage());
        }
        self::assertDirectoryExists($outside);
    }

    #[Test]
    public function itRefusesToOverwriteASubstitutedTargetDuringRecovery(): void
    {
        $root = $this->root();
        $this->interruptAfterFirstReplacement($root);
        $journal = json_decode((string) file_get_contents($root . '/.waaseyaa/site-init.transaction.json'), true, 512, JSON_THROW_ON_ERROR);
        $target = $root . '/' . $journal['items'][0]['path'];
        file_put_contents($target, "externally substituted\n");

        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('substituted generated target');
        new SiteInitializationService($root)->initialize($this->site());
    }

    #[Test]
    public function itRecoversAnInterruptionDuringRollbackWithoutProjectTreeResidue(): void
    {
        $root = $this->root();
        new SiteInitializationService($root)->initialize($this->site());
        $fault = static function (string $stage, int $index): void {
            if ($stage === 'after-replace' && $index === 0) {
                throw new \RuntimeException('begin rollback');
            }
            if ($stage === 'after-rollback-copy') {
                throw new \Error('simulated rollback interruption');
            }
        };
        try {
            new SiteInitializationService($root, $fault)->initialize($this->site('Changed'));
            self::fail('Expected rollback interruption.');
        } catch (\Error) {
        }

        $result = new SiteInitializationService($root)->initialize($this->site('Changed'));

        self::assertTrue($result->recoveredInterruptedTransaction);
        self::assertSame([], glob($root . '/.waaseyaa/site-init-backup-*'));
    }

    #[Test]
    public function cancellationAndRollbackAlwaysLeaveRuntimeControlStateIgnored(): void
    {
        foreach (['cancel', 'rollback'] as $mode) {
            $root = $this->root();
            if ($mode === 'cancel') {
                $result = new SiteInitializationService($root)->initialize($this->site(), authorize: static fn(array $paths): bool => false);
                self::assertTrue($result->cancelled);
            } else {
                $fault = static function (string $stage, int $index): void {
                    if ($stage === 'after-replace' && $index === 0) {
                        throw new \RuntimeException('ordinary publication failure');
                    }
                };
                try {
                    new SiteInitializationService($root, $fault)->initialize($this->site());
                    self::fail('Expected ordinary publication failure.');
                } catch (\RuntimeException $exception) {
                    self::assertSame('ordinary publication failure', $exception->getMessage());
                }
            }

            self::assertFileExists($root . '/.waaseyaa/site-init.lock');
            self::assertFileExists($root . '/.waaseyaa/.gitignore');
            self::assertStringContainsString('site-init.lock', (string) file_get_contents($root . '/.waaseyaa/.gitignore'));
        }
    }

    #[Test]
    public function damagedExtensionMarkersAreARefusedGeneratedCollision(): void
    {
        $root = $this->root();
        $service = new SiteInitializationService($root);
        $service->initialize($this->site());
        $path = $root . '/AGENTS.md';
        file_put_contents($path, str_replace('<!-- waaseyaa:extension:start local-guidance -->', '', (string) file_get_contents($path)));

        $this->expectException(SiteInitializationCollisionException::class);
        $this->expectExceptionMessage('damaged extension region');
        $service->initialize($this->site());
    }

    #[Test]
    public function itRejectsAHardLinkedGeneratedTarget(): void
    {
        $root = $this->root();
        $service = new SiteInitializationService($root);
        $service->initialize($this->site());
        link($root . '/AGENTS.md', $root . '/agents-hardlink');

        $this->expectException(SiteInitializationCollisionException::class);
        $service->initialize($this->site());
    }

    #[Test]
    public function itRejectsAParentSymlinkBeforePublication(): void
    {
        $root = $this->root();
        $outside = $this->root();
        symlink($outside, $root . '/tests');

        $this->expectException(SiteInitializationCollisionException::class);
        new SiteInitializationService($root)->initialize($this->site());
    }

    #[Test]
    public function itRefusesAConcurrentInitializerBeforePublishing(): void
    {
        $root = $this->root();
        mkdir($root . '/.waaseyaa', 0700);
        $lock = fopen($root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->expectException(SiteInitializationLockedException::class);
            new SiteInitializationService($root)->initialize($this->site());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

    private function interruptAfterFirstReplacement(string $root): void
    {
        $fault = static function (string $stage, int $index): void {
            if ($stage === 'after-replace' && $index === 0) {
                throw new \Error('simulated process termination');
            }
        };
        try {
            new SiteInitializationService($root, $fault)->initialize($this->site());
            self::fail('Expected simulated process termination.');
        } catch (\Error) {
        }
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_init_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        return $root;
    }

    /** @return array<string, string> */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative === '.waaseyaa/site-init.lock' || str_contains($relative, '.waaseyaa/site-init-')) {
                continue;
            }
            $snapshot[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

}
