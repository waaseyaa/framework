<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Binds the Dependabot admin-dist rebuild's two structural properties (#2704).
 *
 * The workflow splits an unprivileged `build` job (contents: read, which is
 * where untrusted dependency code executes) from a privileged `publish` job
 * (contents: write + actions: write, which commits and dispatches). `publish`
 * deliberately sets up no PHP. It nonetheless used to run
 * `php bin/admin-dist-acceptance verify`, so it executed the runner's default
 * interpreter; that script uses PHP 8.4+ "new without parentheses" syntax and
 * died with a parse error before the commit step, leaving a stale
 * packages/admin-surface/dist on every Dependabot admin bump.
 *
 * The fix is not "set up PHP in `publish`" — that would run a third-party
 * action inside the write-capable boundary, and that boundary is the security
 * property. Validation moved into `build`, where PHP 8.5 is already pinned,
 * after generation and before the artifact upload, so what is validated is the
 * tree that is uploaded. These tests fail if a future edit moves a PHP or
 * Composer invocation back into the privileged job, drops the pinned
 * interpreter from the validating job, reorders validation after the upload,
 * or introduces a new third-party action into `publish`.
 *
 * AUDITED, UNAFFECTED: .github/workflows/admin-dist.yml (the main-branch
 * rebuild) has a single `build-and-publish` job that pins
 * shivammathur/setup-php @ 8.5 itself, so its PHP invocations always run under
 * a pinned interpreter and it never had this split. The last test here holds
 * that pin in place.
 */
#[CoversNothing]
final class AdminDistWorkflowInterpreterBoundaryTest extends TestCase
{
    private const WORKFLOW = '/.github/workflows/dependabot-admin-dist.yml';

    private const SETUP_PHP = 'shivammathur/setup-php';

    /**
     * Third-party actions the privileged `publish` job is permitted to use.
     * Everything else — setup-php included — must stay in the unprivileged job.
     */
    private const PUBLISH_ALLOWED_ACTIONS = [
        'actions/checkout',
        'actions/download-artifact',
    ];

    /** @var array<string, mixed> */
    private array $workflow;

    protected function setUp(): void
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 2) . self::WORKFLOW);
        self::assertIsArray($parsed);
        $this->workflow = $parsed;
    }

    #[Test]
    public function acceptance_validation_runs_under_the_pinned_interpreter_before_the_artifact_is_uploaded(): void
    {
        $steps = $this->steps('build');

        $validateIndex = $this->indexOfRunStep($steps, 'bin/admin-dist-acceptance verify');
        self::assertNotNull(
            $validateIndex,
            'The unprivileged build job must run `php bin/admin-dist-acceptance verify` itself; '
            . 'the privileged publish job sets up no PHP and cannot parse the script (#2704).',
        );

        $setupPhpIndex = $this->indexOfActionStep($steps, self::SETUP_PHP);
        self::assertNotNull($setupPhpIndex, 'The validating job must set up PHP.');
        self::assertSame(
            '8.5',
            $steps[$setupPhpIndex]['with']['php-version'] ?? null,
            'The validating job must pin PHP 8.5 — the acceptance script needs 8.4+ syntax.',
        );
        self::assertLessThan(
            $validateIndex,
            $setupPhpIndex,
            'PHP must be set up before the acceptance script runs.',
        );

        $buildIndex = $this->indexOfRunStep($steps, 'bin/build-admin-dist');
        self::assertNotNull($buildIndex);
        self::assertLessThan(
            $validateIndex,
            $buildIndex,
            'Validation must run after the dist is generated.',
        );

        $uploadIndex = $this->indexOfActionStep($steps, 'actions/upload-artifact');
        self::assertNotNull($uploadIndex);
        self::assertLessThan(
            $uploadIndex,
            $validateIndex,
            'Validation must run before the artifact upload, so the validated tree and the '
            . 'uploaded tree are the same bytes (#2524).',
        );

        // The upload must publish exactly the paths the verifier reads, so
        // "the bytes that just landed" and "the bytes that were validated"
        // cannot diverge.
        $uploadPaths = array_values(array_filter(array_map(
            trim(...),
            explode("\n", (string) ($steps[$uploadIndex]['with']['path'] ?? '')),
        ), static fn(string $line): bool => $line !== ''));
        self::assertSame(
            [
                'packages/admin-surface/dist',
                'packages/admin-surface/dist.signature',
                'packages/admin-surface/dist.manifest.json',
            ],
            $uploadPaths,
        );
    }

    #[Test]
    public function the_write_capable_job_invokes_no_php_or_composer_and_no_extra_third_party_action(): void
    {
        $permissions = $this->workflow['jobs']['publish']['permissions'] ?? [];
        self::assertSame('write', $permissions['contents'] ?? null);

        foreach ($this->steps('publish') as $step) {
            $run = $step['run'] ?? null;
            if (is_string($run)) {
                self::assertDoesNotMatchRegularExpression(
                    '/(?<![\w\/-])(php|composer)\b/',
                    $run,
                    sprintf(
                        'Step "%s" invokes a PHP/Composer binary inside the contents:write boundary. '
                        . 'That job sets up no interpreter by design (#2704) — move the work to the '
                        . 'unprivileged build job instead of adding setup-php here.',
                        $step['name'] ?? '(unnamed)',
                    ),
                );
            }

            $uses = $step['uses'] ?? null;
            if (!is_string($uses)) {
                continue;
            }
            $action = explode('@', $uses)[0];
            self::assertContains(
                $action,
                self::PUBLISH_ALLOWED_ACTIONS,
                sprintf(
                    'Action "%s" would execute third-party code inside the contents:write + '
                    . 'actions:write boundary. The build/publish separation is the security '
                    . 'property this workflow exists to keep (#2704).',
                    $action,
                ),
            );
        }
    }

    #[Test]
    public function the_privileged_job_keeps_its_shell_level_structural_checks(): void
    {
        $steps = $this->steps('publish');
        $installIndex = $this->indexOfRunStep($steps, 'packages/admin-surface/dist.manifest.json');
        self::assertNotNull($installIndex);
        $install = (string) $steps[$installIndex]['run'];

        // Removing the PHP call must not have removed the shell checks that
        // constrain the shape of the downloaded artifact before it is copied
        // into the tree that gets committed.
        self::assertStringContainsString("= 'dist dist.manifest.json dist.signature '", $install);
        self::assertStringContainsString('! -type d ! -type f -print -quit', $install);
        self::assertStringContainsString("grep -Eq '^[0-9a-f]{64}\$'", $install);
        self::assertStringContainsString('cp -a "$ARTIFACT_ROOT/dist" packages/admin-surface/dist', $install);

        $commit = $this->indexOfRunStep($steps, 'git commit -m');
        self::assertNotNull($commit, 'The privileged job must still commit the rebuilt dist.');
        self::assertLessThan($commit, $installIndex);
    }

    #[Test]
    public function the_main_branch_admin_dist_rebuild_pins_its_own_interpreter(): void
    {
        // Audited alongside #2704: admin-dist.yml has one job, and that job
        // pins setup-php @ 8.5, so its `php bin/...` invocations never fall
        // back to the runner default. No split, nothing to fix.
        $parsed = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/admin-dist.yml');
        self::assertIsArray($parsed);
        self::assertSame(['build-and-publish'], array_keys($parsed['jobs']));

        $steps = $parsed['jobs']['build-and-publish']['steps'];
        $setupPhpIndex = $this->indexOfActionStep($steps, self::SETUP_PHP);
        self::assertNotNull($setupPhpIndex);
        self::assertSame('8.5', $steps[$setupPhpIndex]['with']['php-version'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function steps(string $job): array
    {
        $steps = $this->workflow['jobs'][$job]['steps'] ?? null;
        self::assertIsArray($steps, sprintf('Workflow job "%s" is missing.', $job));

        return array_values($steps);
    }

    /** @param list<array<string, mixed>> $steps */
    private function indexOfRunStep(array $steps, string $needle): ?int
    {
        foreach ($steps as $index => $step) {
            if (is_string($step['run'] ?? null) && str_contains($step['run'], $needle)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $steps */
    private function indexOfActionStep(array $steps, string $action): ?int
    {
        foreach ($steps as $index => $step) {
            if (is_string($step['uses'] ?? null) && str_starts_with($step['uses'], $action . '@')) {
                return $index;
            }
        }

        return null;
    }
}
