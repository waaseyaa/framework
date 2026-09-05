<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\GovernanceCheckEmitter;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(GovernanceCheckEmitter::class)]
final class GovernanceCheckEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('governance-checks', new GovernanceCheckEmitter()->id());
    }

    /**
     * The default-deny companion test is emitted even for `minimal.yaml`,
     * which declares zero permissions/roles/policies/workflows/checks — this
     * is the one deliberate exception to every other governance emitter's
     * "absent governance emits nothing" rule: the open-by-default invariant
     * is exactly as worth pinning for an entity with zero declared policies.
     */
    #[Test]
    public function itEmitsOnlyTheDefaultDenyTestForTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new GovernanceCheckEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(['tests/Blueprint/GovernanceDefaultDenyTest.php'], $this->paths($emission->artifacts));
        self::assertSame(['tests/Blueprint/GovernanceDefaultDenyTest.php'], $emission->companionTests);
        self::assertSame(
            $this->expected('minimal/tests/Blueprint/GovernanceDefaultDenyTest.php'),
            $this->content($emission->artifacts, 'tests/Blueprint/GovernanceDefaultDenyTest.php'),
        );
    }

    #[Test]
    public function itMatchesTheCompleteGoldenFixtureForAllFourCheckKindsAsCompanionTests(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new GovernanceCheckEmitter()->emit($manifest->applicationBlueprint, $manifest);

        $expectedPaths = [
            'tests/Blueprint/EntityAccessChecksTest.php',
            'tests/Blueprint/GovernanceDefaultDenyTest.php',
            'tests/Blueprint/RolePermissionChecksTest.php',
            'tests/Blueprint/WorkflowTransitionChecksTest.php',
        ];
        self::assertSame($expectedPaths, $this->paths($emission->artifacts));
        self::assertSame($expectedPaths, $emission->companionTests);

        foreach ($expectedPaths as $path) {
            self::assertSame(
                $this->expected('complete/' . $path),
                $this->content($emission->artifacts, $path),
                "Golden mismatch for {$path}",
            );
        }
    }

    /**
     * `fixture_present` is the fourth `BlueprintCheckKind` `complete.yaml`
     * declares (`welcome_exists`); no artifact path in the golden roster
     * above corresponds to it — 01D-3 fixture materialization is out of
     * scope for this slice.
     */
    #[Test]
    public function fixturePresentChecksAreNeverEmitted(): void
    {
        $manifest = $this->manifest('complete.yaml');
        self::assertNotEmpty(array_filter(
            $manifest->applicationBlueprint->checks,
            static fn($check) => $check->kind === \Waaseyaa\SiteContract\Blueprint\BlueprintCheckKind::FixturePresent,
        ));

        $emission = new GovernanceCheckEmitter()->emit($manifest->applicationBlueprint, $manifest);

        foreach ($emission->artifacts as $artifact) {
            self::assertStringNotContainsString('FixturePresent', $artifact->content);
            self::assertStringNotContainsString('fixture_present', $artifact->content);
        }
    }

    /**
     * End-to-end proof: every companion test generated for `complete.yaml`,
     * loaded alongside the REAL entity/policy/provider/workflow-definition
     * classes the OTHER governance emitters produce for the same blueprint,
     * and executed as a real PHPUnit run via `proc_open` — not merely
     * syntax-checked. Covers `GovernanceDefaultDenyTest`,
     * `RolePermissionChecksTest`, `EntityAccessChecksTest`, and
     * `WorkflowTransitionChecksTest` (the real `TransitionService` wired
     * against a `TemporarySqliteDatabase`-backed repository).
     */
    #[Test]
    public function everyGeneratedCompanionTestPassesWhenExecutedAgainstTheFullyMaterializedBlueprint(): void
    {
        $manifest = $this->manifest('complete.yaml');

        $emitters = [
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\EntityClassEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\RelationshipEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\ProviderRegistrationEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\PermissionCatalogueEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\AccessPolicyEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\WorkflowDefinitionEmitter(),
            new \Waaseyaa\CLI\Site\Blueprint\Emitter\GovernanceProviderEmitter(),
            new GovernanceCheckEmitter(),
        ];

        $dir = sys_get_temp_dir() . '/waaseyaa_governance_check_e2e_' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);

        try {
            $phpArtifactCount = 0;
            foreach ($emitters as $emitter) {
                $emission = $emitter->emit($manifest->applicationBlueprint, $manifest);
                foreach ($emission->artifacts as $artifact) {
                    if (!str_ends_with($artifact->path, '.php')) {
                        continue;
                    }
                    $path = $dir . '/' . $artifact->path;
                    @mkdir(\dirname($path), 0o700, true);
                    file_put_contents($path, $artifact->content);
                    ++$phpArtifactCount;
                }
            }
            self::assertGreaterThan(0, $phpArtifactCount);

            $bootstrap = <<<'PHP'
                <?php
                require %s;
                spl_autoload_register(function (string $class) use (&$dir): void {
                    $dir = %s;
                    if (str_starts_with($class, 'App\\Tests\\')) {
                        $path = $dir . '/tests/' . str_replace('\\', '/', substr($class, strlen('App\\Tests\\'))) . '.php';
                        if (is_file($path)) { require $path; return; }
                    }
                    if (str_starts_with($class, 'App\\')) {
                        $path = $dir . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                        if (is_file($path)) { require $path; return; }
                    }
                });
                PHP;
            $bootstrap = sprintf(
                $bootstrap,
                var_export(\dirname(__DIR__, 7) . '/vendor/autoload.php', true),
                var_export($dir, true),
            );
            file_put_contents($dir . '/bootstrap.php', $bootstrap);
            file_put_contents($dir . '/phpunit.xml', <<<XML
                <?xml version="1.0"?>
                <phpunit bootstrap="bootstrap.php">
                    <testsuites>
                        <testsuite name="Blueprint">
                            <directory>tests/Blueprint</directory>
                        </testsuite>
                    </testsuites>
                </phpunit>
                XML);

            $phpunitBin = \dirname(__DIR__, 7) . '/vendor/bin/phpunit';
            $process = proc_open(
                ['php', '-d', 'memory_limit=512M', $phpunitBin, '-c', $dir . '/phpunit.xml', '--no-coverage'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $dir,
            );
            self::assertNotFalse($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode, "Generated companion tests failed:\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
            self::assertStringContainsString('OK (5 tests', (string) $stdout);
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($dir);
        }
    }

    /** @param list<GeneratedArtifact> $artifacts @return list<string> */
    private function paths(array $artifacts): array
    {
        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $artifacts);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function content(array $artifacts, string $path): string
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact->content;
            }
        }
        self::fail("No artifact at {$path}");
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
