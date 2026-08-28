<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ExactSourceArtifactTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            if (is_dir($root)) {
                new Filesystem()->remove($root);
            }
        }
    }

    #[Test]
    public function it_builds_a_reproducible_content_addressed_exact_head_archive(): void
    {
        [$root, $sha] = $this->fixtureRepository();

        $first = $this->build($root, $sha, 'first');
        $second = $this->build($root, $sha, 'second');

        self::assertSame(0, $first['exit_code'], $first['output']);
        self::assertSame(0, $second['exit_code'], $second['output']);
        self::assertFileEquals($root . '/first/framework-source.tar', $root . '/second/framework-source.tar');

        $manifest = json_decode((string) file_get_contents($root . '/first/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['schema_version']);
        self::assertSame('framework-exact-source', $manifest['kind']);
        self::assertSame($sha, $manifest['source_sha']);
        self::assertSame(hash_file('sha256', $root . '/first/framework-source.tar'), $manifest['archive_sha256']);
        self::assertSame(filesize($root . '/first/framework-source.tar'), $manifest['archive_bytes']);

        $verified = $this->verify($root, $sha, 'first');
        self::assertSame(0, $verified['exit_code'], $verified['output']);
        self::assertStringContainsString('exact source artifact verified', $verified['output']);
    }

    #[Test]
    public function it_refuses_missing_tampered_and_wrong_head_artifacts(): void
    {
        [$root, $sha] = $this->fixtureRepository();
        self::assertSame(0, $this->build($root, $sha, 'missing')['exit_code']);
        unlink($root . '/missing/framework-source.tar');
        $missing = $this->verify($root, $sha, 'missing');
        self::assertSame(1, $missing['exit_code']);
        self::assertStringContainsString('archive missing', $missing['output']);

        self::assertSame(0, $this->build($root, $sha, 'tampered')['exit_code']);
        file_put_contents($root . '/tampered/framework-source.tar', 'tamper', FILE_APPEND);
        $tampered = $this->verify($root, $sha, 'tampered');
        self::assertSame(1, $tampered['exit_code']);
        self::assertStringContainsString('archive byte count mismatch', $tampered['output']);

        $this->runCommand(['git', 'commit', '--allow-empty', '-m', 'second'], $root);
        $secondSha = trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $root)['output']);
        self::assertSame(0, $this->build($root, $secondSha, 'wrong-head')['exit_code']);
        $wrongHead = $this->verify($root, $sha, 'wrong-head');
        self::assertSame(1, $wrongHead['exit_code']);
        self::assertStringContainsString('wrong-head artifact', $wrongHead['output']);
    }

    #[Test]
    public function it_refuses_a_manifest_that_reblesses_noncanonical_bytes(): void
    {
        [$root, $sha] = $this->fixtureRepository();
        self::assertSame(0, $this->build($root, $sha, 'artifact')['exit_code']);

        $archive = $root . '/artifact/framework-source.tar';
        file_put_contents($archive, 'tamper', FILE_APPEND);
        $manifestPath = $root . '/artifact/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
        $manifest['archive_sha256'] = hash_file('sha256', $archive);
        $manifest['archive_bytes'] = filesize($archive);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $result = $this->verify($root, $sha, 'artifact');
        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('archive bytes do not reproduce the exact commit', $result['output']);
    }

    #[Test]
    public function it_materializes_only_a_verified_artifact_into_a_new_destination(): void
    {
        [$root, $sha] = $this->fixtureRepository();
        self::assertSame(0, $this->build($root, $sha, 'artifact')['exit_code']);

        $result = $this->runCommand([
            $root . '/bin/materialize-exact-source-artifact',
            'artifact',
            $sha,
            'materialized',
        ], $root);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertSame("exact bytes\n", file_get_contents($root . '/materialized/framework/tracked.txt'));
        self::assertStringContainsString('exact source artifact materialized', $result['output']);
    }

    #[Test]
    public function materialization_refuses_an_existing_destination_and_tampered_bytes(): void
    {
        [$root, $sha] = $this->fixtureRepository();
        self::assertSame(0, $this->build($root, $sha, 'artifact')['exit_code']);

        mkdir($root . '/occupied');
        file_put_contents($root . '/occupied/keep.txt', 'keep');
        $occupied = $this->runCommand([
            $root . '/bin/materialize-exact-source-artifact',
            'artifact',
            $sha,
            'occupied',
        ], $root);
        self::assertSame(1, $occupied['exit_code']);
        self::assertStringContainsString('destination already exists', $occupied['output']);
        self::assertSame('keep', file_get_contents($root . '/occupied/keep.txt'));

        file_put_contents($root . '/artifact/framework-source.tar', 'tamper', FILE_APPEND);
        $tampered = $this->runCommand([
            $root . '/bin/materialize-exact-source-artifact',
            'artifact',
            $sha,
            'tampered',
        ], $root);
        self::assertSame(1, $tampered['exit_code']);
        self::assertStringContainsString('archive byte count mismatch', $tampered['output']);
        self::assertDirectoryDoesNotExist($root . '/tampered');
    }

    /** @return array{string, string} */
    private function fixtureRepository(): array
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('sourceartifact', true);
        $this->fixtureRoots[] = $root;
        mkdir($root, 0o777, true);
        mkdir($root . '/bin', 0o777, true);
        copy($this->repoRoot . '/bin/build-exact-source-artifact', $root . '/bin/build-exact-source-artifact');
        copy($this->repoRoot . '/bin/verify-exact-source-artifact', $root . '/bin/verify-exact-source-artifact');
        copy($this->repoRoot . '/bin/materialize-exact-source-artifact', $root . '/bin/materialize-exact-source-artifact');
        file_put_contents($root . '/bin/git', "#!/usr/bin/env bash\nexec git \"\$@\"\n");
        chmod($root . '/bin/build-exact-source-artifact', 0o755);
        chmod($root . '/bin/verify-exact-source-artifact', 0o755);
        chmod($root . '/bin/materialize-exact-source-artifact', 0o755);
        chmod($root . '/bin/git', 0o755);

        $this->runCommand(['git', 'init', '--quiet'], $root);
        $this->runCommand(['git', 'config', 'user.name', 'Fixture'], $root);
        $this->runCommand(['git', 'config', 'user.email', 'fixture@example.test'], $root);
        file_put_contents($root . '/tracked.txt', "exact bytes\n");
        $this->runCommand(['git', 'add', 'tracked.txt', 'bin'], $root);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'fixture'], $root);
        $sha = trim($this->runCommand(['git', 'rev-parse', 'HEAD'], $root)['output']);

        return [$root, $sha];
    }

    /** @return array{exit_code: int, output: string} */
    private function build(string $root, string $sha, string $directory): array
    {
        return $this->runCommand([$root . '/bin/build-exact-source-artifact', $directory, $sha], $root);
    }

    /** @return array{exit_code: int, output: string} */
    private function verify(string $root, string $sha, string $directory): array
    {
        return $this->runCommand([$root . '/bin/verify-exact-source-artifact', $directory, $sha], $root);
    }

    /** @param list<string> $command @return array{exit_code: int, output: string} */
    private function runCommand(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd, null, null, 30);
        $exitCode = $process->run();

        return [
            'exit_code' => $exitCode,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
