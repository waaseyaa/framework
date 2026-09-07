<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Command\BimaajiInstallCommand;
use Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\InstalledManifest;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Install\TargetFile;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;

/**
 * Unit coverage for the install command's write loop.
 *
 * The end-to-end behaviour also has integration coverage under
 * `tests/Integration/PhaseN/BimaajiInstall/` and a packaged-consumer proof
 * at `tests/PackagedForm/check-bimaaji-skill-resources`. Neither records
 * line coverage, so this class is what binds the command's own lines for
 * the changed-line coverage gate.
 */
#[CoversClass(BimaajiInstallCommand::class)]
final class BimaajiInstallCommandTest extends TestCase
{
    private string $tempDir = '';
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: null;
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_bimaaji_install_unit_' . uniqid();
        mkdir($this->tempDir . '/skills', 0o755, true);
        $this->writeSkill('alpha', "---\nname: Skill Alpha\ndescription: First fixture\n---\n\n# Alpha\n\nBody for alpha.");
        $this->writeSkill('beta', "---\nname: Skill Beta\ndescription: Second fixture\n---\n\n# Beta\n\nBody for beta.");
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            @chdir($this->originalCwd);
        }
        new Filesystem()->remove($this->tempDir);
    }

    #[Test]
    public function writesTheClientTargetFileFromTheResolvedSkillSet(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        $contents = (string) file_get_contents($this->tempDir . '/.cursorrules');
        self::assertStringContainsString('## Skill Alpha', $contents);
        self::assertStringContainsString('## Skill Beta', $contents);
        self::assertStringContainsString('Client cursor: 1 written, 0 unchanged, 0 skipped.', $tester->getOutput());
    }

    #[Test]
    public function warnsWhenSkillsAreRequestedForAClientThatCannotRepresentThem(): void // #2660 Part B
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--features=guidelines,skills', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(
            'Warning: skills for client "cursor" are folded into .cursorrules',
            $tester->getOutput(),
        );
    }

    #[Test]
    public function doesNotWarnForAClientThatSupportsPerSkillDeliveryOrWhenSkillsWereNotRequested(): void // #2660 Part B
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--features=guidelines', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringNotContainsString('Warning:', $tester->getOutput());
    }

    #[Test]
    public function anIdenticalTargetCountsAsUnchanged(): void
    {
        $this->tester()->execute(['--client=cursor', '--force']);

        $second = $this->tester();
        $second->execute(['--client=cursor']);

        self::assertSame(0, $second->getExitCode());
        self::assertStringContainsString('Client cursor: 0 written, 1 unchanged, 0 skipped.', $second->getOutput());
    }

    #[Test]
    public function refreshesOnlyTheManagedRegionOfAnExistingTarget(): void
    {
        $this->tester()->execute(['--client=cursor', '--force']);

        $target = $this->tempDir . '/.cursorrules';
        file_put_contents(
            $target,
            "MY PREAMBLE\n\n" . (string) file_get_contents($target) . "\nMY POSTSCRIPT\n",
        );
        $this->writeSkill('alpha', "---\nname: Skill Alpha\ndescription: First fixture\n---\n\n# Alpha\n\nRevised upstream body.");

        $tester = $this->tester();
        $tester->execute(['--client=cursor']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        $merged = (string) file_get_contents($target);
        self::assertStringContainsString('MY PREAMBLE', $merged);
        self::assertStringContainsString('MY POSTSCRIPT', $merged);
        self::assertStringContainsString('Revised upstream body.', $merged);
        self::assertStringNotContainsString('Body for alpha.', $merged);
    }

    #[Test]
    public function refusesToReplaceAnUnmarkedFileWithoutForce(): void
    {
        file_put_contents($this->tempDir . '/.cursorrules', "Hand written, no markers.\n");

        $tester = $this->tester();
        $tester->execute(['--client=cursor']);

        self::assertSame(1, $tester->getExitCode());
        self::assertSame("Hand written, no markers.\n", file_get_contents($this->tempDir . '/.cursorrules'));
        self::assertStringContainsString(ManagedRegion::BEGIN, $tester->getOutput());
        self::assertStringContainsString('pass --force to overwrite', $tester->getOutput());
    }

    #[Test]
    public function forceReplacesAnUnmarkedFile(): void
    {
        file_put_contents($this->tempDir . '/.cursorrules', "Hand written, no markers.\n");

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(ManagedRegion::BEGIN, (string) file_get_contents($this->tempDir . '/.cursorrules'));
    }

    #[Test]
    public function dryRunPrintsTheWriteSetWithoutTouchingTheFilesystem(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--dry-run']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertFileDoesNotExist($this->tempDir . '/.cursorrules');
        self::assertStringContainsString('[DRY-RUN] would write .cursorrules', $tester->getOutput());
    }

    #[Test]
    public function dryRunAnnouncesAManagedRegionRefreshDistinctly(): void
    {
        $this->tester()->execute(['--client=cursor', '--force']);
        $this->writeSkill('alpha', "---\nname: Skill Alpha\ndescription: First fixture\n---\n\n# Alpha\n\nRevised upstream body.");

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--dry-run']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('would refresh the managed region of .cursorrules', $tester->getOutput());
        self::assertStringNotContainsString('Revised upstream body.', (string) file_get_contents($this->tempDir . '/.cursorrules'));
    }

    #[Test]
    public function anUnknownClientSuggestsTheNearestMatchAndExitsNonZero(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=curser', '--force']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('unknown client "curser"', $tester->getOutput());
        self::assertStringContainsString('Did you mean "cursor"?', $tester->getOutput());
    }

    #[Test]
    public function commaSeparatedClientsAreSplitAndDeduplicated(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor,CURSOR', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertSame(1, substr_count($tester->getOutput(), 'Client cursor:'));
    }

    #[Test]
    public function aMissingClientOnNonInteractiveStdinIsAHardError(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('--client is required when stdin is non-TTY', $tester->getOutput());
    }

    #[Test]
    public function aSandboxEscapeIsRejectedBeforeAnyWrite(): void
    {
        $escape = sys_get_temp_dir() . '/waaseyaa_unit_escape_' . uniqid() . '.md';

        $tester = $this->tester([$this->stubTransformer('attacker', $escape)]);
        $tester->execute(['--client=attacker', '--force']);

        self::assertFileDoesNotExist($escape);
        self::assertStringContainsString('rejected suspicious target path', $tester->getOutput());
        self::assertStringContainsString('Client attacker: 0 written, 0 unchanged, 1 skipped.', $tester->getOutput());
    }

    #[Test]
    public function aMissingSkillDirectoryProducesTheActionableDiagnostic(): void
    {
        new Filesystem()->remove($this->tempDir . '/skills');

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('bimaaji:install:', $tester->getOutput());
        self::assertStringContainsString('does not exist', $tester->getOutput());
        self::assertStringContainsString($this->tempDir . '/skills', $tester->getOutput());
    }

    #[Test]
    public function recordsWhatItGeneratedInTheOwnershipManifest(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());

        $manifest = InstalledManifest::load($this->tempDir);
        $targets = $manifest->targetsFor('cursor');

        self::assertArrayHasKey('.cursorrules', $targets);
        self::assertSame(
            sha1((string) file_get_contents($this->tempDir . '/.cursorrules')),
            $targets['.cursorrules'],
            'The recorded sha1 must be of the bytes actually left on disk.',
        );
    }

    #[Test]
    public function prunesARecordedTargetTheCurrentSetNoLongerProduces(): void
    {
        // Install with a transformer that writes two files, then re-run with
        // one that writes only the first: the second is now retired.
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $first = $this->tester([$two]);
        $first->execute(['--client=multi', '--force']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());
        self::assertFileExists($this->tempDir . '/b.md');

        $one = $this->stubMultiFileTransformer('multi', ['a.md']);
        $second = $this->tester([$one]);
        $second->execute(['--client=multi', '--force']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertFileExists($this->tempDir . '/a.md');
        self::assertFileDoesNotExist($this->tempDir . '/b.md');
        self::assertStringContainsString('Removed retired target b.md', $second->getOutput());
        self::assertArrayNotHasKey('b.md', InstalledManifest::load($this->tempDir)->targetsFor('multi'));
    }

    #[Test]
    public function neutralisesARetiredTargetTheConsumerHasEdited(): void
    {
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $this->tester([$two])->execute(['--client=multi', '--force']);

        file_put_contents(
            $this->tempDir . '/b.md',
            (string) file_get_contents($this->tempDir . '/b.md') . "\nMy own trailing note.\n",
        );

        $one = $this->stubMultiFileTransformer('multi', ['a.md']);
        $second = $this->tester([$one]);
        $second->execute(['--client=multi', '--force']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertFileExists($this->tempDir . '/b.md', 'Deleting would take hand-authored bytes with it.');
        $contents = (string) file_get_contents($this->tempDir . '/b.md');
        self::assertStringContainsString('My own trailing note.', $contents);
        self::assertStringContainsString('has been retired', $contents);
        self::assertStringContainsString('Retired the managed region of b.md', $second->getOutput());
    }

    #[Test]
    public function releasesARetiredTargetThatLostItsMarkers(): void
    {
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $this->tester([$two])->execute(['--client=multi', '--force']);

        // Rewritten wholesale by the consumer: ownership can no longer be
        // demonstrated, so the file must be left entirely alone.
        file_put_contents($this->tempDir . '/b.md', "Wholly mine now.\n");

        $one = $this->stubMultiFileTransformer('multi', ['a.md']);
        $second = $this->tester([$one]);
        $second->execute(['--client=multi', '--force']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertSame("Wholly mine now.\n", file_get_contents($this->tempDir . '/b.md'));
        self::assertStringContainsString('ownership can no longer be demonstrated', $second->getOutput());
        self::assertArrayNotHasKey('b.md', InstalledManifest::load($this->tempDir)->targetsFor('multi'));
    }

    #[Test]
    public function neverPrunesAPathTheManifestDoesNotClaim(): void
    {
        $one = $this->stubMultiFileTransformer('multi', ['a.md']);
        $this->tester([$one])->execute(['--client=multi', '--force']);

        // Same naming family, never generated by us.
        file_put_contents($this->tempDir . '/b.md', "Never ours.\n");

        $second = $this->tester([$one]);
        $second->execute(['--client=multi', '--force']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertSame("Never ours.\n", file_get_contents($this->tempDir . '/b.md'));
    }

    #[Test]
    public function aStillDeclaredTargetIsNeverPrunedWhenThisRunCouldNotWriteIt(): void
    {
        // Regression: the current set is what the transformer DECLARES. If it
        // were derived from what the run managed to write, a refused overwrite
        // or a permission error would look like an upstream removal and the
        // pruner would delete a file that is still current.
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $this->tester([$two])->execute(['--client=multi', '--force']);
        $recordedSha1 = InstalledManifest::load($this->tempDir)->targetsFor('multi')['b.md'];

        // Diverge b.md so a write is attempted, then make it unwritable so the
        // attempt fails and b.md drops out of this run's write results.
        file_put_contents($this->tempDir . '/b.md', ManagedRegion::wrap('locally changed'));
        chmod($this->tempDir . '/b.md', 0o444);
        $second = $this->tester([$two]);
        $second->execute(['--client=multi', '--force']);
        chmod($this->tempDir . '/b.md', 0o644);

        // b.md is still declared by the transformer, so it is not retired at
        // all — it must not reach the pruner in any state.
        self::assertFileExists($this->tempDir . '/b.md');
        self::assertStringNotContainsString('retired target', $second->getOutput());
        self::assertStringNotContainsString('released', $second->getOutput());
        // And its ownership record survives, so a genuine retirement later
        // can still prune it.
        self::assertSame(
            $recordedSha1,
            InstalledManifest::load($this->tempDir)->targetsFor('multi')['b.md'] ?? null,
        );
    }

    #[Test]
    public function dryRunOnAFreshChangeDoesNotReportTheWriteSetAsRetired(): void
    {
        // Regression: with the current set derived from write results, a dry
        // run wrote nothing and so reported every target as retired.
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $this->tester([$two])->execute(['--client=multi', '--force']);

        // Force both files to differ so the dry run has real work to report.
        file_put_contents($this->tempDir . '/a.md', ManagedRegion::wrap('stale'));
        file_put_contents($this->tempDir . '/b.md', ManagedRegion::wrap('stale'));

        $tester = $this->tester([$two]);
        $tester->execute(['--client=multi', '--dry-run']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringNotContainsString('retired target', $tester->getOutput());
        self::assertFileExists($this->tempDir . '/a.md');
        self::assertFileExists($this->tempDir . '/b.md');
    }

    #[Test]
    public function dryRunReportsAPruneWithoutPerformingIt(): void
    {
        $two = $this->stubMultiFileTransformer('multi', ['a.md', 'b.md']);
        $this->tester([$two])->execute(['--client=multi', '--force']);
        $manifestBefore = (string) file_get_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH);

        $one = $this->stubMultiFileTransformer('multi', ['a.md']);
        $second = $this->tester([$one]);
        $second->execute(['--client=multi', '--dry-run']);

        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertStringContainsString('[DRY-RUN] would remove retired target b.md', $second->getOutput());
        self::assertFileExists($this->tempDir . '/b.md');
        self::assertSame(
            $manifestBefore,
            file_get_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH),
        );
    }

    #[Test]
    public function failingToPersistTheOwnershipManifestIsANonZeroExit(): void
    {
        // The manifest is the provenance boundary that makes later pruning
        // safe. Generating files whose ownership was never recorded must not
        // report success. Uses a plain file where the directory belongs, so
        // this proof needs no symlink and runs on every platform.
        file_put_contents($this->tempDir . '/.waaseyaa', "not a directory\n");

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertFileExists($this->tempDir . '/.cursorrules', 'The install itself still ran.');
        self::assertStringContainsString('ownership manifest', $tester->getOutput());
        self::assertStringContainsString('will not be able to prune', $tester->getOutput());
    }

    #[Test]
    public function aCorruptSkillDocumentProducesTheActionableDiagnostic(): void
    {
        $this->writeSkill('alpha', "---\nname: Broken\ndescription: never closed\n\nbody");

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('is corrupt', $tester->getOutput());
        self::assertStringContainsString('alpha/SKILL.md', $tester->getOutput());
    }

    private function writeSkill(string $id, string $contents): void
    {
        $dir = $this->tempDir . '/skills/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($dir . '/SKILL.md', $contents);
    }

    /**
     * @param iterable<ClientTransformerInterface>|null $transformers
     */
    private function tester(?iterable $transformers = null): CliTester
    {
        chdir($this->tempDir);

        $command = new BimaajiInstallCommand(
            transformers: $transformers ?? [new CursorClientTransformer()],
            skillSetParser: new SkillSetParser($this->tempDir . '/skills', configuredOverride: true),
        );

        return CliTester::for(
            definition: $this->installDefinition(),
            container: $this->container($command),
        );
    }

    private function installDefinition(): HandlerCommand
    {
        foreach (new BimaajiServiceProvider()->consoleCommands() as $definition) {
            if ($definition->name === 'bimaaji:install') {
                return $definition;
            }
        }

        self::fail('BimaajiServiceProvider does not yield a bimaaji:install command definition.');
    }

    private function container(BimaajiInstallCommand $command): ContainerInterface
    {
        return new class ($command) implements ContainerInterface {
            public function __construct(private readonly BimaajiInstallCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id !== BimaajiInstallCommand::class) {
                    throw new \RuntimeException("Container stub: unknown service id {$id}.");
                }

                return $this->command;
            }

            public function has(string $id): bool
            {
                return $id === BimaajiInstallCommand::class;
            }
        };
    }

    /**
     * A transformer emitting one marker-bounded file per named path, so a
     * later run can shrink the set and exercise the pruner.
     *
     * @param list<string> $paths
     */
    private function stubMultiFileTransformer(string $clientId, array $paths): ClientTransformerInterface
    {
        return new class ($clientId, $paths) implements ClientTransformerInterface {
            /** @param list<string> $paths */
            public function __construct(
                private readonly string $clientId,
                private readonly array $paths,
            ) {}

            public function clientId(): string
            {
                return $this->clientId;
            }

            /**
             * @param list<ParsedSkill> $skills
             * @return list<TargetFile>
             */
            public function targetFiles(array $skills): array
            {
                $files = [];
                foreach ($this->paths as $targetPath) {
                    $files[] = new TargetFile(
                        path: $targetPath,
                        content: ManagedRegion::wrap('Generated content for ' . $targetPath),
                        sourceSkill: null,
                    );
                }

                return $files;
            }
        };
    }

    private function stubTransformer(string $clientId, string $maliciousPath): ClientTransformerInterface
    {
        return new class ($clientId, $maliciousPath) implements ClientTransformerInterface {
            public function __construct(
                private readonly string $clientId,
                private readonly string $maliciousPath,
            ) {}

            public function clientId(): string
            {
                return $this->clientId;
            }

            /**
             * @param list<ParsedSkill> $skills
             * @return list<TargetFile>
             */
            public function targetFiles(array $skills): array
            {
                return [new TargetFile(
                    path: $this->maliciousPath,
                    content: "Attempted sandbox escape — should never be written.\n",
                    sourceSkill: null,
                )];
            }
        };
    }
}
