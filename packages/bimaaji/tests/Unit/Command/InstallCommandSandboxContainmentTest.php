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
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\InstalledManifest;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Install\TargetFile;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;

/**
 * Containment proofs for `bimaaji:install`: nothing it does may read,
 * write, delete or rewrite a byte outside the project root.
 *
 * Every test here plants a **sentinel** — a file in a second temp directory
 * that is not the project root — and asserts its bytes are identical after
 * the run. A sentinel is what makes these proofs positive: asserting only
 * that the command printed a rejection would still pass if it printed the
 * rejection *and* clobbered the file anyway.
 *
 * The pruning path matters more than the write path. A redirected write
 * overwrites one file; a redirected prune calls `unlink()`, and a redirected
 * neutralisation rewrites content. Both are covered.
 *
 * **Platform.** These tests require symbolic links, and they assert that the
 * link was created rather than skipping when it was not, so they fail loudly
 * on a platform that cannot make one instead of passing vacuously. That is
 * deliberate: a containment test that quietly does nothing is worse than no
 * test. This is the same POSIX-shaped surface `CLAUDE.md` already documents
 * for the release-tooling tests. On Windows, `is_link()` does not report a
 * directory junction, which is why the production guard also resolves the
 * target itself rather than relying on `is_link()` alone.
 */
#[CoversClass(BimaajiInstallCommand::class)]
final class InstallCommandSandboxContainmentTest extends TestCase
{
    private string $projectRoot = '';
    private string $outside = '';
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: null;
        $unique = uniqid();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_bimaaji_contain_project_' . $unique;
        $this->outside = sys_get_temp_dir() . '/waaseyaa_bimaaji_contain_outside_' . $unique;
        mkdir($this->projectRoot . '/skills/alpha', 0o755, true);
        mkdir($this->outside, 0o755, true);
        file_put_contents(
            $this->projectRoot . '/skills/alpha/SKILL.md',
            "---\nname: Skill Alpha\ndescription: fixture\n---\n\n# Alpha\n\nBody for alpha.",
        );
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            @chdir($this->originalCwd);
        }
        $filesystem = new Filesystem();
        $filesystem->remove($this->projectRoot);
        $filesystem->remove($this->outside);
    }

    #[Test]
    public function aTargetFileSymlinkNeverRedirectsAWrite(): void
    {
        $sentinel = $this->plantSentinel('write-sentinel.txt', "External bytes. Untouchable.\n");
        $this->link($sentinel, $this->projectRoot . '/.cursorrules');

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        $this->assertSentinelUntouched($sentinel, "External bytes. Untouchable.\n");
        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('is a symbolic link', $tester->getOutput());
    }

    #[Test]
    public function aTargetFileSymlinkNeverRedirectsAPrune(): void
    {
        // Record ownership of .cursorrules honestly, then swap the real file
        // for a link to the sentinel and retire the client's whole target set.
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        $generated = (string) file_get_contents($this->projectRoot . '/.cursorrules');

        // The sentinel carries the exact recorded bytes, so an unguarded
        // pruner would take the highest-confidence branch and unlink it.
        $sentinel = $this->plantSentinel('prune-sentinel.txt', $generated);
        unlink($this->projectRoot . '/.cursorrules');
        $this->link($sentinel, $this->projectRoot . '/.cursorrules');

        $second = $this->tester(transformers: [$this->emptyTransformer('cursor')]);
        $second->execute(['--client=cursor', '--force']);

        self::assertFileExists($sentinel, 'A redirected prune would have unlinked the sentinel.');
        $this->assertSentinelUntouched($sentinel, $generated);
        self::assertStringNotContainsString('Removed retired target', $second->getOutput());
    }

    #[Test]
    public function aTargetFileSymlinkNeverRedirectsANeutralisation(): void
    {
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());

        // Marker-bounded content whose sha1 differs from the record: exactly
        // the shape that routes an unguarded pruner into the rewrite branch.
        $sentinelBytes = "Mine.\n\n" . ManagedRegion::wrap('External managed block.') . "\nAlso mine.\n";
        $sentinel = $this->plantSentinel('neutralise-sentinel.txt', $sentinelBytes);
        unlink($this->projectRoot . '/.cursorrules');
        $this->link($sentinel, $this->projectRoot . '/.cursorrules');

        $second = $this->tester(transformers: [$this->emptyTransformer('cursor')]);
        $second->execute(['--client=cursor', '--force']);

        $this->assertSentinelUntouched($sentinel, $sentinelBytes);
        self::assertStringNotContainsString('Retired the managed region', $second->getOutput());
    }

    #[Test]
    public function aSymlinkedWaaseyaaDirectoryNeverRedirectsTheManifestWrite(): void
    {
        $sentinel = $this->plantSentinel('manifest-dir/sentinel.txt', "Outside the project.\n");
        $this->link(dirname($sentinel), $this->projectRoot . '/.waaseyaa');

        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        $this->assertSentinelUntouched($sentinel, "Outside the project.\n");
        self::assertFileDoesNotExist(
            dirname($sentinel) . '/bimaaji-install.json',
            'The ownership manifest was written outside the project root.',
        );
        // Losing the manifest is a provenance failure, not a warning.
        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('refusing to write the ownership manifest', $tester->getOutput());
    }

    #[Test]
    public function aSymlinkedTargetDirectoryNeverRedirectsASkillWrite(): void
    {
        $sentinel = $this->plantSentinel('claude-dir/sentinel.txt', "Outside the project.\n");
        mkdir($this->projectRoot . '/.claude', 0o755, true);
        $this->link(dirname($sentinel), $this->projectRoot . '/.claude/skills');

        $tester = $this->tester();
        $tester->execute(['--client=claude', '--force']);

        $this->assertSentinelUntouched($sentinel, "Outside the project.\n");
        self::assertSame(
            [],
            glob(dirname($sentinel) . '/waaseyaa-*') ?: [],
            'Skill directories were created outside the project root.',
        );
        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
    }

    #[Test]
    public function aRegularTargetInsideTheProjectIsStillWritten(): void
    {
        // The guards must not be so broad that they reject ordinary work.
        $tester = $this->tester();
        $tester->execute(['--client=cursor', '--force']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertFileExists($this->projectRoot . '/.cursorrules');
        self::assertFileExists($this->projectRoot . '/' . InstalledManifest::RELATIVE_PATH);
    }

    private function plantSentinel(string $relativePath, string $contents): string
    {
        $path = $this->outside . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Create a symlink, failing the test loudly if the platform cannot.
     */
    private function link(string $target, string $linkPath): void
    {
        self::assertTrue(
            @symlink($target, $linkPath),
            sprintf(
                'Could not create the symbolic link %s -> %s. This containment test needs one; it fails rather '
                . 'than passing vacuously on a platform without symlink support.',
                $linkPath,
                $target,
            ),
        );
    }

    private function assertSentinelUntouched(string $sentinel, string $expected): void
    {
        self::assertFileExists($sentinel, 'The sentinel outside the project root was deleted.');
        self::assertSame(
            $expected,
            file_get_contents($sentinel),
            'The sentinel outside the project root was modified.',
        );
    }

    /**
     * @param iterable<ClientTransformerInterface>|null $transformers
     */
    private function tester(?iterable $transformers = null): CliTester
    {
        chdir($this->projectRoot);

        $command = new BimaajiInstallCommand(
            transformers: $transformers ?? [
                new \Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer(),
                new \Waaseyaa\Bimaaji\Install\Client\ClaudeClientTransformer(),
            ],
            skillSetParser: new SkillSetParser($this->projectRoot . '/skills', configuredOverride: true),
        );

        return CliTester::for(
            definition: $this->installDefinition(),
            container: $this->container($command),
        );
    }

    /**
     * A transformer that declares no targets, so everything previously
     * recorded for its client becomes retired on the next run.
     */
    private function emptyTransformer(string $clientId): ClientTransformerInterface
    {
        return new class ($clientId) implements ClientTransformerInterface {
            public function __construct(private readonly string $clientId) {}

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
                return [];
            }
        };
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
}
