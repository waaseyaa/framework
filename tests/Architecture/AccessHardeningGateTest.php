<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Fixture-driven proof that `bin/check-access-hardening` scans repository
 * content only (#2925).
 *
 * The gate used to walk `packages/` with a RecursiveDirectoryIterator, so a
 * populated `packages/<pkg>/vendor/` (vendor libraries ship their own `src/`
 * directories) or a nested git worktree under `packages/` produced phantom
 * AH004 findings in a developer clone that hosted CI — one tree, no nested
 * checkouts — never saw. The gate accepts the repository root as its first
 * argument, so each case builds a throwaway git repository whose only real
 * source is clean, seeds the non-repository trees with a route that has no
 * access posture, and runs the real script against it.
 */
#[CoversNothing]
final class AccessHardeningGateTest extends TestCase
{
    private const string UNSAFE_ROUTE = "<?php RouteBuilder::create('/unsafe')->controller('x')->build();\n";

    private string $gate;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->gate = dirname(__DIR__, 2) . '/bin/check-access-hardening';
        self::assertFileExists($this->gate);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_accesshardening_' . uniqid('', true);
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function a_clean_repository_fixture_passes(): void
    {
        $this->writeRepositoryFixture();

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "A clean fixture tree must pass.\n{$out}");
        self::assertStringContainsString('access-hardening: OK', $out);
    }

    #[Test]
    public function nested_worktrees_and_nested_vendor_are_not_findings(): void
    {
        $this->writeRepositoryFixture();
        $this->git('worktree', 'add', '-q', $this->tmpRoot . '/.worktrees/wf1', '-b', 'wf1');
        $this->git('worktree', 'add', '-q', $this->tmpRoot . '/.claude/worktrees/wf2', '-b', 'wf2');
        $this->git('worktree', 'add', '-q', $this->tmpRoot . '/packages/nested-checkout', '-b', 'nested');

        foreach ([
            // The exact production phantom (#2925): a vendored copy of a
            // framework package under packages/<pkg>/vendor/.
            'packages/ai-agent/vendor/waaseyaa/foundation/src/Kernel/BuiltinRouteRegistrar.php',
            '.worktrees/wf1/packages/routing/src/UnsafeRoutes.php',
            '.claude/worktrees/wf2/packages/routing/src/UnsafeRoutes.php',
            'packages/nested-checkout/packages/routing/src/UnsafeRoutes.php',
        ] as $relative) {
            $this->write($relative, self::UNSAFE_ROUTE);
        }

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "Non-repository trees must not produce findings.\n{$out}");
        self::assertStringNotContainsString('AH004', $out);
    }

    #[Test]
    public function an_untracked_source_file_in_the_repository_is_still_scanned(): void
    {
        // Negative control for the fixture above: the same unsafe route in a
        // real (untracked, not ignored) source location must still fail, so
        // the exclusion is the ignore boundary — not "untracked means skip".
        $this->writeRepositoryFixture();
        $this->write('packages/routing/src/UnsafeRoutes.php', self::UNSAFE_ROUTE);

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An unsafe route in repository content must fail.\n{$out}");
        self::assertStringContainsString('AH004 packages/routing/src/UnsafeRoutes.php', $out);
    }

    #[Test]
    public function a_root_outside_any_git_repository_fails_closed(): void
    {
        $this->writeSafeSurfaces();

        [$exit, $out] = $this->runGate();

        self::assertNotSame(0, $exit, "The gate must not silently pass a tree it cannot enumerate.\n{$out}");
        self::assertStringContainsString('git', $out);
    }

    private function writeRepositoryFixture(): void
    {
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'access-hardening@example.test');
        $this->git('config', 'user.name', 'Access Hardening Test');
        $this->write('.gitignore', ".worktrees/\n.claude/worktrees/\npackages/*/vendor/\n");
        $this->writeSafeSurfaces();
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', 'fixture');
    }

    /** The AH002/AH003 protected surfaces, each carrying its required executable call. */
    private function writeSafeSurfaces(): void
    {
        $this->write('packages/seo/src/SitemapGenerator.php', "<?php \$query->setAccount(\$account);\n");
        $this->write('packages/seo/src/Llms/LlmsTxtGenerator.php', "<?php \$query->setAccount(\$account);\n");
        $this->write(
            'packages/ssr/src/SsrPageHandler.php',
            "<?php \$this->accessHandler->check(\$entity, 'view', \$account); new WorkflowVisibilityFilter();\n",
        );
        $this->write(
            'packages/api/src/JsonApiController.php',
            "<?php \$this->validateQueryFields(); \$this->queryFieldForbidden(); \$this->rejectForbiddenSort();\n",
        );
        $this->write(
            'packages/graphql/src/Resolver/EntityResolver.php',
            "<?php \$this->assertQueryableFields(); \$this->queryFieldForbidden(); \$this->rejectForbiddenSort();\n",
        );
        $this->write(
            'packages/admin-surface/src/Host/GenericAdminSurfaceHost.php',
            "<?php \$this->validateSurfaceQueryFields(); \$this->isFieldViewForbidden();\n",
        );
        $this->write('packages/routing/src/SafeRoutes.php', "<?php RouteBuilder::create('/safe')->controller('x')->allowAll()->build();\n");
    }

    private function write(string $relative, string $contents): void
    {
        $file = $this->tmpRoot . '/' . $relative;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }
        file_put_contents($file, $contents);
    }

    private function git(string ...$arguments): void
    {
        new Process(['git', '-C', $this->tmpRoot, ...$arguments])->mustRun();
    }

    /** @return array{0: int, 1: string} */
    private function runGate(): array
    {
        $process = new Process([PHP_BINARY, $this->gate, $this->tmpRoot], $this->tmpRoot);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
