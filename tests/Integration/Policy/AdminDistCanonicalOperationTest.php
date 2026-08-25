<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * #2524 — binds the committed repository state to the one canonical
 * combined-source rebuild + acceptance operation.
 *
 * The rebuild half needs Node 24 and a full Nuxt run, so it is not executed
 * here. What IS executed here is the committed-state half: the acceptance
 * manifest that travels with a release must describe the committed published
 * tree exactly, and the entrypoint must still be wired to build twice.
 */
#[CoversNothing]
final class AdminDistCanonicalOperationTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    #[Test]
    public function the_committed_repository_passes_acceptance_verification(): void
    {
        // proc_open received neither cwd nor env, so null for both preserves
        // inheritance; timeout null because the scan walks the whole published
        // tree and was never time-bounded (#2491 runner shape).
        $process = new Process(
            [PHP_BINARY, $this->root() . '/bin/admin-dist-acceptance', 'verify'],
            $this->root(),
            null,
            null,
            null,
        );
        $exit = $process->run();

        self::assertSame(0, $exit, $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function the_acceptance_manifest_and_marker_roster_are_committed_next_to_the_published_tree(): void
    {
        $surface = $this->root() . '/packages/admin-surface';
        self::assertFileExists($surface . '/dist.manifest.json');
        self::assertFileExists($surface . '/dist.markers.json');
        self::assertFileExists($surface . '/dist.signature');

        $manifest = json_decode((string) file_get_contents($surface . '/dist.manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['manifestVersion']);
        self::assertSame('waaseyaa/admin-surface', $manifest['release']['package']);
        self::assertSame(
            trim((string) file_get_contents($surface . '/dist.signature')),
            $manifest['source']['signature'],
            'The manifest must name the same source signature the freshness gate compares.',
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $manifest['published']['treeDigest']);
        self::assertGreaterThan(0, $manifest['published']['fileCount']);
        self::assertContains('acceptance', $manifest['identityExcludes']);
    }

    #[Test]
    public function the_entrypoint_builds_twice_into_independent_disposable_snapshots(): void
    {
        $build = (string) file_get_contents($this->root() . '/bin/build-admin-dist');

        self::assertStringContainsString('admin-dist-acceptance', $build);
        self::assertStringContainsString('guard', $build);
        self::assertStringContainsString('--first=', $build);
        self::assertStringContainsString('--second=', $build);
        self::assertStringContainsString('run-hermetic-admin-build" --scan-only', $build);
        self::assertSame(
            2,
            preg_match_all('/^build_once "\\$SNAPSHOTS\\/(?:first|second)"$/m', $build),
            'The entrypoint must invoke exactly two builds, into two independent snapshot directories.',
        );
        self::assertStringContainsString('mktemp -d', $build);
        self::assertStringContainsString('trap', $build, 'The disposable snapshot directories must be cleaned up.');
    }

    #[Test]
    public function the_freshness_gate_remains_authoritative_and_covers_the_new_procedure_surface(): void
    {
        $freshness = (string) file_get_contents($this->root() . '/bin/check-admin-dist-fresh');

        // Unweakened: the gate still fails closed on a missing marker and on a
        // signature mismatch.
        self::assertStringContainsString('admin dist signature marker missing', $freshness);
        self::assertStringContainsString('D6 staleness gate', $freshness);
        // Extended: the new acceptance tooling is part of the procedure roster,
        // so a procedure change stales the freshness marker.
        self::assertStringContainsString("'bin/admin-dist-acceptance'", $freshness);

        $composer = json_decode((string) file_get_contents($this->root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertContains('@check-admin-dist-fresh', $composer['scripts']['verify']);
        self::assertContains('@check-admin-dist-manifest', $composer['scripts']['verify']);
        self::assertSame('php bin/admin-dist-acceptance verify', $composer['scripts']['check-admin-dist-manifest']);

        $ci = (string) file_get_contents($this->root() . '/.github/workflows/ci.yml');
        self::assertStringContainsString('run_gate check-admin-dist-fresh', $ci);
        self::assertStringContainsString('run_gate check-admin-dist-manifest', $ci);

        $gates = json_decode((string) file_get_contents($this->root() . '/tools/preflight-gates.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($gates);
        $ids = array_column($gates['gates'], 'id');
        self::assertContains('check-admin-dist-fresh', $ids);
        self::assertContains('check-admin-dist-manifest', $ids);
    }

    #[Test]
    public function every_marker_the_served_bundle_test_pins_is_declared_in_the_marker_roster(): void
    {
        $markers = json_decode(
            (string) file_get_contents($this->root() . '/packages/admin-surface/dist.markers.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($markers);
        $values = array_column($markers['markers'], 'value');

        // The served-bundle assertions in AdminDistContentTest and the declared
        // marker roster must not become two competing vocabularies (#2524).
        foreach ([
            'Opening',
            'data-anchor',
            'mcp_approvals_title',
            'mcp.approval.decide',
            'page-builder-embed',
            'entity-editor-embed',
            'waaseyaa.admin.embed.lifecycle.v2',
        ] as $pinned) {
            self::assertContains(
                $pinned,
                $values,
                sprintf('The served-bundle marker "%s" must be declared in dist.markers.json.', $pinned),
            );
        }
    }
}
