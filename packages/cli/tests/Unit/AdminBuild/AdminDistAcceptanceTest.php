<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\AdminBuild\AdminDistAcceptance;
use Waaseyaa\CLI\AdminBuild\AdminDistAcceptanceException;
use Waaseyaa\CLI\AdminBuild\AdminDistAcceptanceManifest;
use Waaseyaa\CLI\AdminBuild\AdminDistAcceptanceVerifier;
use Waaseyaa\CLI\AdminBuild\AdminDistSourceMarkerPolicy;
use Waaseyaa\CLI\AdminBuild\AdminDistTreeInventory;

/**
 * #2524 — the canonical combined-source rebuild + acceptance operation.
 *
 * These fixtures stand in for the two independent Nuxt build snapshots that
 * bin/build-admin-dist produces, so the acceptance half of the operation is
 * provable without a Node toolchain.
 */
#[CoversClass(AdminDistAcceptance::class)]
#[CoversClass(AdminDistAcceptanceManifest::class)]
#[CoversClass(AdminDistAcceptanceVerifier::class)]
#[CoversClass(AdminDistSourceMarkerPolicy::class)]
#[CoversClass(AdminDistTreeInventory::class)]
final class AdminDistAcceptanceTest extends TestCase
{
    private const SOURCE_SIGNATURE = 'aaaaaaaabbbbbbbbccccccccddddddddeeeeeeeeffffffff0000000011111111';
    private const BUILD_SIGNATURE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const BUILD_ID = 'waaseyaa-0123456789abcdef0123456789abcdef';
    private const BUILD_MANIFEST = '_nuxt/builds/latest.json';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            new Filesystem()->remove($dir);
        }
        $this->tempDirs = [];
    }

    #[Test]
    public function the_operation_refuses_a_single_build_snapshot_presented_twice(): void
    {
        $root = $this->projectFixture();
        $build = $this->buildSnapshot(['_nuxt/app.abc.js' => 'Opening data-anchor']);

        $this->expectException(AdminDistAcceptanceException::class);
        $this->expectExceptionMessage('duplicate-build-snapshot');

        new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $build,
            secondBuild: $build,
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
    }

    #[Test]
    public function a_non_reproducible_pair_of_builds_fails_closed_and_leaves_the_committed_tree_untouched(): void
    {
        $root = $this->projectFixture(['_nuxt/previous.js' => 'Opening data-anchor']);
        $before = AdminDistTreeInventory::scan($root . '/packages/admin-surface/dist')->digest;
        $first = $this->buildSnapshot(['_nuxt/app.abc.js' => 'Opening data-anchor']);
        $second = $this->buildSnapshot(['_nuxt/app.abc.js' => 'Opening data-anchor DRIFT']);

        try {
            new AdminDistAcceptance()->accept(
                projectRoot: $root,
                firstBuild: $first,
                secondBuild: $second,
                sourceSignature: self::SOURCE_SIGNATURE,
                buildIdSignature: self::BUILD_SIGNATURE,
                toolchain: $this->toolchain(),
            );
            self::fail('A non-reproducible build pair must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('build-not-reproducible', $exception->errorCode);
            self::assertContains('_nuxt/app.abc.js', $exception->details);
        }

        self::assertSame(
            $before,
            AdminDistTreeInventory::scan($root . '/packages/admin-surface/dist')->digest,
            'A refused acceptance must not have replaced the committed published tree.',
        );
    }

    #[Test]
    public function acceptance_replaces_the_published_tree_wholesale_and_inventories_obsolete_removals(): void
    {
        $root = $this->projectFixture([
            '_nuxt/stale.0000.js' => 'Opening data-anchor',
            '_nuxt/kept.1111.js' => 'old bytes',
            'legacy/index.html' => '<html>legacy</html>',
        ]);
        $output = [
            '_nuxt/kept.1111.js' => 'new bytes',
            '_nuxt/fresh.2222.js' => 'Opening data-anchor',
        ];

        $result = new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );

        self::assertSame(['_nuxt/fresh.2222.js'], $result->added);
        self::assertSame(['_nuxt/kept.1111.js'], $result->modified);
        self::assertSame(['_nuxt/stale.0000.js', 'legacy/index.html'], $result->removed);
        self::assertFileDoesNotExist($root . '/packages/admin-surface/dist/_nuxt/stale.0000.js');
        self::assertFileDoesNotExist($root . '/packages/admin-surface/dist/legacy/index.html');
        self::assertDirectoryDoesNotExist($root . '/packages/admin-surface/dist/legacy');
        self::assertSame(
            $result->published->digest,
            AdminDistTreeInventory::scan($root . '/packages/admin-surface/dist')->digest,
        );
    }

    #[Test]
    public function two_conflicting_generated_trees_converge_on_the_same_combined_source_output(): void
    {
        // The transplant shape from #2524: two Admin branches each committed a
        // different generated dist. Whichever conflict side is checked out, the
        // canonical operation must land on the same published bytes AND the
        // same manifest identity.
        $output = [
            '_nuxt/entry.9999.js' => 'Opening data-anchor combined',
            'index.html' => '<html>combined</html>',
        ];
        $sideA = $this->projectFixture([
            '_nuxt/branch-a.aaaa.js' => 'side A chunk',
            'index.html' => '<html>side A</html>',
        ]);
        $sideB = $this->projectFixture([
            '_nuxt/branch-b.bbbb.js' => 'side B chunk',
            '_nuxt/branch-b-extra.cccc.js' => 'side B extra chunk',
            'index.html' => '<html>side B</html>',
        ]);

        $acceptedA = new AdminDistAcceptance()->accept(
            projectRoot: $sideA,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        $acceptedB = new AdminDistAcceptance()->accept(
            projectRoot: $sideB,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );

        self::assertSame($acceptedA->published->digest, $acceptedB->published->digest);
        self::assertSame(
            $acceptedA->manifest->identityDigest(),
            $acceptedB->manifest->identityDigest(),
            'Manifest identity must depend on the combined-source output, never on which conflict side was selected.',
        );
        self::assertSame(['_nuxt/branch-a.aaaa.js'], $acceptedA->removed);
        self::assertSame(['_nuxt/branch-b-extra.cccc.js', '_nuxt/branch-b.bbbb.js'], $acceptedB->removed);
        self::assertSame(
            $this->treeHashes($sideA . '/packages/admin-surface/dist'),
            $this->treeHashes($sideB . '/packages/admin-surface/dist'),
        );
    }

    #[Test]
    public function re_running_on_identical_input_produces_zero_diff_and_a_byte_identical_manifest(): void
    {
        $root = $this->projectFixture();
        $output = ['_nuxt/entry.4444.js' => 'Opening data-anchor'];

        $first = new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        $manifestPath = $root . '/' . AdminDistAcceptanceManifest::PATH;
        $treeAfterFirst = $this->treeHashes($root . '/packages/admin-surface/dist');
        $manifestAfterFirst = (string) file_get_contents($manifestPath);
        self::assertTrue($first->manifestRewritten);

        $second = new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            // A different toolchain patch release must not perturb identity.
            toolchain: ['nodePin' => '24', 'nodeRuntime' => 'v24.99.0', 'npmRuntime' => '11.99.0'],
        );

        self::assertFalse($second->publishedTreeChanged);
        self::assertFalse($second->manifestRewritten, 'A no-op acceptance must leave the committed manifest byte-unchanged.');
        self::assertSame($treeAfterFirst, $this->treeHashes($root . '/packages/admin-surface/dist'));
        self::assertSame($manifestAfterFirst, (string) file_get_contents($manifestPath));
        self::assertSame($first->manifest->identityDigest(), $second->manifest->identityDigest());
    }

    #[Test]
    public function the_manifest_separates_the_published_tree_from_the_broader_intermediate_output(): void
    {
        $root = $this->projectFixture();
        $output = [
            '_nuxt/entry.5555.js' => 'Opening data-anchor',
            'index.html' => '<html>ok</html>',
        ];
        $intermediate = $this->buildSnapshot([
            'public/_nuxt/entry.5555.js' => 'Opening data-anchor',
            'public/index.html' => '<html>ok</html>',
            'nitro.json' => '{}',
            'server/index.mjs' => 'server bundle',
            '_nuxt-cache/chunk.map' => 'sourcemap',
        ]);

        $result = new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
            intermediateRoot: $intermediate,
        );

        $document = $result->manifest->document;
        self::assertSame(2, $document['published']['fileCount']);
        self::assertSame(5, $document['acceptance']['intermediate']['artifactCount']);
        self::assertNotSame(
            $document['published']['treeDigest'],
            $document['acceptance']['intermediate']['inventoryDigest'],
            'The published tree digest must never be the intermediate output digest.',
        );
        self::assertSame(2, $document['acceptance']['builds']);
        self::assertTrue($document['acceptance']['reproducible']);
        self::assertSame('v24.19.0', $document['acceptance']['toolchain']['nodeRuntime']);
        self::assertContains('acceptance', $document['identityExcludes']);
    }

    #[Test]
    public function a_missing_requested_source_marker_fails_acceptance(): void
    {
        $root = $this->projectFixture();

        try {
            new AdminDistAcceptance()->accept(
                projectRoot: $root,
                firstBuild: $this->buildSnapshot(['_nuxt/entry.js' => 'data-anchor only']),
                secondBuild: $this->buildSnapshot(['_nuxt/entry.js' => 'data-anchor only']),
                sourceSignature: self::SOURCE_SIGNATURE,
                buildIdSignature: self::BUILD_SIGNATURE,
                toolchain: $this->toolchain(),
            );
            self::fail('A bundle missing a requested source marker must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('source-marker-unsatisfied', $exception->errorCode);
            self::assertContains('edit-busy-feedback', $exception->details);
        }
    }

    #[Test]
    public function a_changed_source_marker_turns_the_committed_state_verification_red(): void
    {
        $root = $this->projectFixture();
        $output = [
            '_nuxt/entry.6666.js' => 'Opening data-anchor',
            self::BUILD_MANIFEST => '{"id":"' . self::BUILD_ID . '"}',
        ];
        new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        self::assertSame([], new AdminDistAcceptanceVerifier()->verify($root));

        $this->writeMarkers($root, [
            ['id' => 'edit-busy-feedback', 'scope' => 'bundle-js', 'value' => 'Closing'],
            ['id' => 'wayfinding-anchors', 'scope' => 'bundle-js', 'value' => 'data-anchor'],
        ]);

        $problems = new AdminDistAcceptanceVerifier()->verify($root);
        self::assertNotSame([], $problems);
        self::assertStringContainsString('edit-busy-feedback', implode("\n", $problems));
    }

    #[Test]
    public function verification_detects_a_hand_edited_published_tree(): void
    {
        $root = $this->projectFixture();
        $output = ['_nuxt/entry.7777.js' => 'Opening data-anchor'];
        new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        file_put_contents(
            $root . '/packages/admin-surface/dist/_nuxt/entry.7777.js',
            'Opening data-anchor hand-edited',
        );

        $problems = new AdminDistAcceptanceVerifier()->verify($root);

        self::assertNotSame([], $problems);
        self::assertStringContainsString('published tree digest', implode("\n", $problems));
    }

    #[Test]
    public function verification_detects_a_tampered_identity_digest(): void
    {
        $root = $this->projectFixture();
        $output = ['_nuxt/entry.8888.js' => 'Opening data-anchor'];
        new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        $manifestPath = $root . '/' . AdminDistAcceptanceManifest::PATH;
        $document = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['identityDigest'] = str_repeat('0', 64);
        file_put_contents($manifestPath, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $problems = new AdminDistAcceptanceVerifier()->verify($root);

        self::assertNotSame([], $problems);
        self::assertStringContainsString('identity digest', implode("\n", $problems));
    }

    #[Test]
    public function the_manifest_carries_the_release_identity_a_consumer_can_accept_without_a_candidate_hash(): void
    {
        $root = $this->projectFixture();
        $output = ['_nuxt/entry.aaaa.js' => 'Opening data-anchor'];

        $result = new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );

        $document = $result->manifest->document;
        self::assertSame('waaseyaa/admin-surface', $document['release']['package']);
        self::assertSame('dist', $document['release']['distPath']);
        self::assertSame('dist.manifest.json', $document['release']['manifestPath']);
        // The identity a consumer re-accepts is content, not a branch commit:
        // scanning the installed dist must reproduce the published digest.
        self::assertSame(
            $document['published']['treeDigest'],
            AdminDistTreeInventory::scan($root . '/packages/admin-surface/dist')->digest,
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $document['identityDigest']);
    }

    #[Test]
    public function the_manifest_ships_inside_the_consumer_package_next_to_the_published_tree(): void
    {
        self::assertSame('packages/admin-surface/dist.manifest.json', AdminDistAcceptanceManifest::PATH);
        self::assertSame('packages/admin-surface/dist.markers.json', AdminDistSourceMarkerPolicy::PATH);
    }

    #[Test]
    public function a_marker_is_never_satisfied_by_a_string_that_only_spans_a_file_boundary(): void
    {
        // "Opening" exists only if two compiled chunks are glued together. A
        // per-file check refuses it; a whole-tree concatenation would have
        // accepted it, and unspecified iteration order made even that
        // nondeterministic.
        $root = $this->projectFixture();
        $output = [
            '_nuxt/a.0001.js' => 'data-anchor Open',
            '_nuxt/b.0002.js' => 'ing chunk',
        ];

        try {
            new AdminDistAcceptance()->accept(
                projectRoot: $root,
                firstBuild: $this->buildSnapshot($output),
                secondBuild: $this->buildSnapshot($output),
                sourceSignature: self::SOURCE_SIGNATURE,
                buildIdSignature: self::BUILD_SIGNATURE,
                toolchain: $this->toolchain(),
            );
            self::fail('A marker satisfied only across a file boundary must be refused.');
        } catch (AdminDistAcceptanceException $exception) {
            self::assertSame('source-marker-unsatisfied', $exception->errorCode);
            self::assertSame(['edit-busy-feedback'], $exception->details);
        }
    }

    #[Test]
    public function a_published_bundle_without_a_nuxt_build_manifest_fails_verification(): void
    {
        $root = $this->projectFixture();
        $output = [
            '_nuxt/entry.bbbb.js' => 'Opening data-anchor',
            self::BUILD_MANIFEST => '{"id":"' . self::BUILD_ID . '"}',
        ];
        new AdminDistAcceptance()->accept(
            projectRoot: $root,
            firstBuild: $this->buildSnapshot($output),
            secondBuild: $this->buildSnapshot($output),
            sourceSignature: self::SOURCE_SIGNATURE,
            buildIdSignature: self::BUILD_SIGNATURE,
            toolchain: $this->toolchain(),
        );
        self::assertSame([], new AdminDistAcceptanceVerifier()->verify($root));

        unlink($root . '/packages/admin-surface/dist/' . self::BUILD_MANIFEST);

        $problems = new AdminDistAcceptanceVerifier()->verify($root);

        self::assertNotSame([], $problems);
        self::assertStringContainsString('latest.json', implode("\n", $problems));
    }

    /** @param array<string, string> $distFiles */
    private function projectFixture(array $distFiles = []): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_accept_' . bin2hex(random_bytes(8));
        $this->tempDirs[] = $root;
        mkdir($root . '/packages/admin-surface/dist', 0o755, true);
        foreach ($distFiles === [] ? ['index.html' => '<html>seed</html>'] : $distFiles as $relative => $contents) {
            $this->writeFile($root . '/packages/admin-surface/dist/' . $relative, $contents);
        }
        $this->writeMarkers($root, [
            ['id' => 'edit-busy-feedback', 'scope' => 'bundle-js', 'value' => 'Opening'],
            ['id' => 'wayfinding-anchors', 'scope' => 'bundle-js', 'value' => 'data-anchor'],
        ]);

        return $root;
    }

    /** @param list<array{id: string, scope: string, value: string}> $markers */
    private function writeMarkers(string $root, array $markers): void
    {
        $this->writeFile(
            $root . '/' . AdminDistSourceMarkerPolicy::PATH,
            json_encode(
                ['markersVersion' => 1, 'markers' => $markers],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
    }

    /** @param array<string, string> $files */
    private function buildSnapshot(array $files): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_admin_snapshot_' . bin2hex(random_bytes(8));
        $this->tempDirs[] = $root;
        mkdir($root, 0o755, true);
        foreach ($files as $relative => $contents) {
            $this->writeFile($root . '/' . $relative, $contents);
        }

        return $root;
    }

    /** @return array{nodePin: string, nodeRuntime: string, npmRuntime: string} */
    private function toolchain(): array
    {
        return ['nodePin' => '24', 'nodeRuntime' => 'v24.19.0', 'npmRuntime' => '11.6.2'];
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
        file_put_contents($path, $contents);
    }

    /** @return array<string, string> */
    private function treeHashes(string $root): array
    {
        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $hashes[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($hashes, SORT_STRING);

        return $hashes;
    }
}
