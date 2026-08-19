<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2426. v0.1.0-alpha.294 and v0.1.0-alpha.295 both shipped unbootable for new
 * installs. The property was not unproven — `Skeleton Smoke (Packaged-form CI)`
 * was red on both — but that proof resolves the published artifact from
 * Packagist, so it cannot run until after the tag exists, and a post-publication
 * proof cannot block a publication.
 *
 * These assertions pin the replacement: a pre-tag proof built from the release
 * commit's own bytes, wired both as an ordinary pull-request check and as a
 * release-cut gate that precedes every irreversible action.
 */
#[CoversNothing]
final class FreshInstallBootGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-fresh-install-boot';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_proof_and_its_probe_exist_and_are_runnable(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');
        self::assertFileExists($this->repoRoot . '/tests/FreshInstallBoot/boot.php');

        // The governed installation phase belongs to the harness — it runs
        // `waaseyaa install:init` exactly as a deployment does — so the probe
        // proves only what a consumer gets afterwards: ordinary runtime boot
        // with no privileged setup of its own.
        $harnessSource = (string) file_get_contents($harness);
        self::assertStringContainsString('waaseyaa install:init', $harnessSource);
        self::assertStringContainsString('Installation is complete.', $harnessSource);
        // Running it twice must stay successful and must not mint a second
        // generation; that idempotence is part of what the gate proves.
        self::assertStringContainsString('Configuration already initialized', $harnessSource);

        $probe = (string) file_get_contents($this->repoRoot . '/tests/FreshInstallBoot/boot.php');
        self::assertStringNotContainsString(
            'bootForSchemaSync',
            $probe,
            'The probe must not perform privileged setup the governed install path owns.',
        );
        self::assertStringContainsString('HttpKernel', $probe);
        self::assertStringContainsString('fresh-install kernel boot OK', $probe);

        // Boot alone is not the property. v0.1.0-alpha.296 booted a fresh
        // install and still could not save, because the PRE_SAVE listener
        // resolves workflow configuration. The gate went green over that while
        // Skeleton Smoke went red on the same commit, so the write is pinned
        // here explicitly.
        self::assertStringContainsString('->save(', $probe);
        self::assertStringContainsString('->find(', $probe);
        self::assertStringContainsString('fresh-install entity round-trip OK', $probe);

        // Path repositories, not the published artifact — that is what makes
        // the proof runnable before a tag exists. The word "Packagist" appears
        // in the harness's own comments explaining exactly this, so assert the
        // behaviour instead: no registry fetch, no create-project.
        self::assertStringContainsString('"type" => "path"', $harnessSource);
        self::assertStringNotContainsString('repo.packagist.org', $harnessSource);
        self::assertStringNotContainsString('create-project', $harnessSource);
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/fresh-install-boot', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }

    #[Test]
    public function the_release_cut_is_gated_on_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/release-cut.yml');

        self::assertStringContainsString('Five gates enforce this', $workflow);
        self::assertStringContainsString('Gate 5/5: fresh packaged-form install boots on the release commit', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);

        foreach (['Gate 1/5', 'Gate 2/5', 'Gate 3/5', 'Gate 4/5'] as $gate) {
            self::assertStringContainsString($gate, $workflow, 'Gate numbering must stay consistent.');
        }
    }

    #[Test]
    public function the_proof_precedes_every_irreversible_release_action(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/release-cut.yml');

        $gate = strpos($workflow, 'Gate 5/5: fresh packaged-form install boots');
        self::assertIsInt($gate);

        // Everything below either creates the tag, publishes it, or mints the
        // credential that makes those possible. A proof that ran after any of
        // them would be exactly the post-publication proof this replaces.
        foreach ([
            'Mint the release-identity token' => 'the release-identity token is minted',
            'Tag the gated commit and fast-forward main (atomic)' => 'the tag and main push happen',
        ] as $needle => $description) {
            $position = strpos($workflow, $needle);
            self::assertIsInt($position, sprintf('release-cut.yml must still contain "%s".', $needle));
            self::assertLessThan(
                $position,
                $gate,
                sprintf('The fresh-install boot proof must run before %s.', $description),
            );
        }
    }
}
