<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ci/skeleton-create-project`'s ordinary lane proves the committed skeleton
 * floor resolves from the **already-published** release line. A first-party
 * package the skeleton references that has never been split cannot satisfy
 * that, and cannot until one release cut later — #2655 is the first time this
 * has come up, because before it the skeleton named only `waaseyaa/framework`.
 *
 * The job therefore adds a path repository scoped with `only` to exactly the
 * names in `support/skeleton-unpublished-packages.json`; every other name still
 * comes from Packagist, so the proof is narrowed by exactly one package rather
 * than abandoned.
 *
 * That narrowing is only acceptable while it is impossible to avoid, so it is
 * fenced from three sides: this test keeps the roster honest and bound to the
 * workflow, the job prints a notice for every entry it applies, and a live
 * control step fails the moment a listed package appears on Packagist — which
 * is what forces the entry back out instead of leaving a permanent hole. **An
 * empty roster is the correct steady state.**
 */
#[CoversNothing]
final class SkeletonUnpublishedPackagesTest extends TestCase
{
    private const string ROSTER = 'support/skeleton-unpublished-packages.json';
    private const string WORKFLOW = '.github/workflows/ci.yml';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /**
     * Every entry must be a real first-party package that the skeleton really
     * references. Without this, the roster could quietly become a way to
     * resolve arbitrary names from the checkout.
     */
    #[Test]
    public function every_entry_names_a_first_party_package_the_skeleton_references(): void
    {
        $skeleton = $this->json('skeleton/composer.json');
        $referenced = array_merge(
            array_keys($skeleton['require'] ?? []),
            array_keys($skeleton['require-dev'] ?? []),
        );

        foreach ($this->roster() as $entry) {
            $package = (string) $entry['package'];
            $path = (string) $entry['path'];

            self::assertStringStartsWith('waaseyaa/', $package);
            self::assertSame(
                'packages/' . substr($package, strlen('waaseyaa/')),
                $path,
                'The scoped path repository must point at the package it claims to provide.',
            );
            self::assertSame(
                $package,
                $this->json($path . '/composer.json')['name'] ?? null,
                sprintf('%s does not name %s.', $path, $package),
            );
            self::assertContains(
                $package,
                $referenced,
                sprintf(
                    '%s is rostered as unpublished, but the skeleton does not reference it — the entry '
                    . 'buys nothing and should be removed.',
                    $package,
                ),
            );
        }
    }

    /**
     * An exception with no recorded reason and no removal condition is how a
     * one-cycle workaround becomes permanent.
     */
    #[Test]
    public function every_entry_records_its_issue_reason_and_removal_condition(): void
    {
        foreach ($this->roster() as $entry) {
            $package = (string) $entry['package'];

            self::assertMatchesRegularExpression(
                '/^#\d+$/',
                (string) ($entry['issue'] ?? ''),
                sprintf('%s must name the issue that introduced it.', $package),
            );
            foreach (['reason', 'remove_when', 'introduced_in'] as $field) {
                self::assertNotEmpty(
                    $entry[$field] ?? null,
                    sprintf('%s must record a non-empty %s.', $package, $field),
                );
            }
        }
    }

    /**
     * The roster is inert unless the workflow reads it, and the narrowing is
     * only narrow if the repository it adds is scoped with `only`. A path
     * repository without `only` would let the checkout satisfy *any* name and
     * silently void the job's whole claim.
     */
    #[Test]
    public function the_workflow_consumes_the_roster_and_scopes_every_repository_it_adds(): void
    {
        $workflow = $this->read(self::WORKFLOW);

        self::assertStringContainsString(self::ROSTER, $workflow);
        self::assertStringContainsString('\\"only\\":[\\"${pkg}\\"]', $workflow);
        self::assertStringContainsString('name: The unpublished-package exception is still necessary', $workflow);
        self::assertStringContainsString('repo.packagist.org/p2/${pkg}.json', $workflow);
    }

    /**
     * The release lane is untouched. `SOURCE_SKELETON` already resolves the
     * whole graph from the checkout for a release commit, for its own stated
     * reason; this roster exists for the ordinary lane, and must not become a
     * second, competing mechanism there.
     */
    #[Test]
    public function the_release_lane_still_owns_its_own_path_repositories(): void
    {
        $workflow = $this->read(self::WORKFLOW);

        self::assertStringContainsString(
            'composer config --working-dir="$work/skel-proj" repositories.packages path "$GITHUB_WORKSPACE/packages/*"',
            $workflow,
            'The release lane resolves every first-party name from the checkout and does not consult the roster.',
        );
    }

    /** @return list<array<string, mixed>> */
    private function roster(): array
    {
        $roster = $this->json(self::ROSTER);
        self::assertSame(1, $roster['schema_version'] ?? null);
        self::assertIsArray($roster['packages'] ?? null);

        /** @var list<array<string, mixed>> $packages */
        $packages = $roster['packages'];

        return $packages;
    }

    /** @return array<string, mixed> */
    private function json(string $relative): array
    {
        return json_decode($this->read($relative), true, flags: JSON_THROW_ON_ERROR);
    }

    private function read(string $relative): string
    {
        $path = $this->repoRoot . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
