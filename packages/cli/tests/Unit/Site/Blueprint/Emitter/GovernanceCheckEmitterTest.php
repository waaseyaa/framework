<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\GovernanceCheckEmitter;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheck;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheckKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowState;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
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
     * syntax-checked. Covers `GovernanceDefaultDenyTest` (2 per-entity denial
     * methods plus the 3 decision-(i) invariant methods added by #2788
     * review F7), `RolePermissionChecksTest`, `EntityAccessChecksTest` (one
     * `deny` and, since #2788 review F2, one genuine `allow` check),
     * and `WorkflowTransitionChecksTest` (one `denied` and one genuine
     * `allowed` check — the real `TransitionService` wired against a
     * `TemporarySqliteDatabase`-backed repository) — 10 methods in total.
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
            $this->assertEveryMaterializedPolicyCarriesADiscoverablePolicyAttribute($dir);

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
            self::assertStringContainsString('OK (10 tests', (string) $stdout);
        } finally {
            new \Symfony\Component\Filesystem\Filesystem()->remove($dir);
        }
    }

    /**
     * PackageManifestCompiler-style discovery over the materialized `src/`
     * tree: every generated `src/Access/*Policy.php` class must carry a
     * reflectable `#[PolicyAttribute]`, otherwise it is never wired into
     * `EntityAccessHandler` at boot and every grant it declares is dead
     * (#2788 review F1). This scans the actual materialized files that fed
     * the real PHPUnit run above, not the golden fixtures.
     */
    private function assertEveryMaterializedPolicyCarriesADiscoverablePolicyAttribute(string $dir): void
    {
        $policyDir = $dir . '/src/Access';
        if (!is_dir($policyDir)) {
            return;
        }

        $checked = 0;
        foreach (glob($policyDir . '/*Policy.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/#\[PolicyAttribute\(entityType:\s*\'[a-z0-9_]+\'\)\]\s*\nfinal class/',
                $source,
                "Materialized {$file} has no discoverable #[PolicyAttribute] immediately preceding its class declaration.",
            );
            self::assertStringContainsString('use Waaseyaa\\Access\\Gate\\PolicyAttribute;', $source);
            ++$checked;
        }
        self::assertGreaterThan(0, $checked, 'Expected at least one generated policy class to scan for #[PolicyAttribute].');
    }

    /**
     * #2788 review F5: previously an unguarded `\assert($binding !== null)`
     * inside `renderWorkflowTransitionMethod()` — an `AssertionError` in a
     * dev environment, a `TypeError` in production once
     * `zend.assertions=-1` strips the assert. Refused at compile time,
     * before any artifact is rendered, with a pointer instead.
     */
    #[Test]
    public function aWorkflowTransitionCheckOnAnUnboundWorkflowIsRefusedGen007(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $blueprint = new ApplicationBlueprint(
            contractVersion: 1,
            entities: $manifest->applicationBlueprint->entities,
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [
                'editorial' => new BlueprintWorkflow(
                    id: 'editorial',
                    label: 'Editorial',
                    initialState: 'draft',
                    states: ['draft' => new BlueprintWorkflowState('draft', 'Draft', false)],
                    transitions: [],
                    bindings: [],
                ),
            ],
            fixtures: [],
            checks: [
                'unbound_check' => new BlueprintCheck(
                    'unbound_check',
                    BlueprintCheckKind::WorkflowTransition,
                    role: 'viewer',
                    workflow: 'editorial',
                    transition: 'publish',
                    expect: 'denied',
                ),
            ],
        );

        try {
            new GovernanceCheckEmitter()->emit($blueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $exception->violations[0]->code);
            self::assertStringContainsString('zero bindings', $exception->violations[0]->message);
        }
    }

    /**
     * #2788 review F6: `pascalCase()` strips `_` and `-` alike, so two check
     * ids of the same kind can collide on the generated test method name —
     * previously an unparseable duplicate method declaration in the
     * companion test file. Refused at compile time instead.
     */
    #[Test]
    public function twoChecksOfTheSameKindPascalCasingToTheSameMethodNameAreRefusedGen006(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $blueprint = new ApplicationBlueprint(
            contractVersion: 1,
            entities: $manifest->applicationBlueprint->entities,
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [],
            fixtures: [],
            checks: [
                'editor_ok' => new BlueprintCheck('editor_ok', BlueprintCheckKind::RolePermission, role: 'editor', permission: 'edit article', expect: 'granted'),
                'editor-ok' => new BlueprintCheck('editor-ok', BlueprintCheckKind::RolePermission, role: 'editor', permission: 'edit article', expect: 'granted'),
            ],
        );

        try {
            new GovernanceCheckEmitter()->emit($blueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
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
