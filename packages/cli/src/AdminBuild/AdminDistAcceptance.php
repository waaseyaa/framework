<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * The acceptance half of the one canonical combined-source Admin dist rebuild
 * (#2524).
 *
 * Two INDEPENDENT build snapshots go in. The operation refuses a single
 * snapshot presented twice, refuses a non-reproducible pair, refuses a bundle
 * that does not satisfy the declared source-contract markers, replaces the
 * committed published tree WHOLESALE (never merging hashed chunks), proves
 * every obsolete generated path is gone, and emits the versioned acceptance
 * manifest.
 *
 * Because the published tree is replaced wholesale from the build output, two
 * conflicting committed generated trees converge on identical bytes and an
 * identical manifest identity regardless of which conflict side was checked
 * out first.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final class AdminDistAcceptance
{
    public const string PUBLISHED_PATH = 'packages/admin-surface/dist';
    public const string SIGNATURE_PATH = 'packages/admin-surface/dist.signature';
    public const string RELEASE_PACKAGE = 'waaseyaa/admin-surface';

    /**
     * @param array{nodePin: string, nodeRuntime: string, npmRuntime: string} $toolchain
     */
    public function accept(
        string $projectRoot,
        string $firstBuild,
        string $secondBuild,
        string $sourceSignature,
        string $buildIdSignature,
        array $toolchain,
        ?string $intermediateRoot = null,
    ): AdminDistAcceptanceResult {
        $root = $this->validatedRoot($projectRoot);
        foreach ([$sourceSignature, $buildIdSignature] as $signature) {
            if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
                throw new AdminDistAcceptanceException('signature-invalid');
            }
        }

        $first = realpath($firstBuild);
        $second = realpath($secondBuild);
        if (!is_string($first) || !is_dir($first) || !is_string($second) || !is_dir($second)) {
            throw new AdminDistAcceptanceException('build-snapshot-missing');
        }
        if ($first === $second) {
            throw new AdminDistAcceptanceException('duplicate-build-snapshot', [$first]);
        }

        $firstInventory = AdminDistTreeInventory::scan($first);
        $secondInventory = AdminDistTreeInventory::scan($second);
        if ($firstInventory->digest !== $secondInventory->digest) {
            throw new AdminDistAcceptanceException(
                'build-not-reproducible',
                $firstInventory->differingPaths($secondInventory),
            );
        }
        if ($firstInventory->fileCount === 0) {
            throw new AdminDistAcceptanceException('build-snapshot-empty');
        }

        // Markers are checked BEFORE the committed tree is touched, so a bundle
        // that lost a requested source marker never lands.
        $markers = AdminDistSourceMarkerPolicy::load($root);
        $unsatisfied = $markers->unsatisfied($first);
        if ($unsatisfied !== []) {
            throw new AdminDistAcceptanceException('source-marker-unsatisfied', $unsatisfied);
        }

        $publishedPath = $root . '/' . self::PUBLISHED_PATH;
        $previous = is_dir($publishedPath) ? AdminDistTreeInventory::scan($publishedPath) : null;
        [$added, $modified, $removed] = $this->transition($previous, $firstInventory);

        $this->replaceWholesale($first, $publishedPath);

        foreach ($removed as $obsolete) {
            if (file_exists($publishedPath . '/' . $obsolete)) {
                throw new AdminDistAcceptanceException('obsolete-artifact-retained', [$obsolete]);
            }
        }
        $published = AdminDistTreeInventory::scan($publishedPath);
        if ($published->digest !== $firstInventory->digest) {
            throw new AdminDistAcceptanceException('publish-verification-failed');
        }

        file_put_contents($root . '/' . self::SIGNATURE_PATH, $sourceSignature . "\n");

        $manifest = AdminDistAcceptanceManifest::fromDocument([
            'manifestVersion' => AdminDistAcceptanceManifest::VERSION,
            'identityExcludes' => AdminDistAcceptanceManifest::IDENTITY_EXCLUDES,
            'release' => [
                'package' => self::RELEASE_PACKAGE,
                'distPath' => 'dist',
                'manifestPath' => 'dist.manifest.json',
                'acceptance' => 'Scan the installed dist directory and require the same published.treeDigest.',
            ],
            'source' => [
                'signature' => $sourceSignature,
                'buildIdSignature' => $buildIdSignature,
                'buildId' => 'waaseyaa-' . substr($buildIdSignature, 0, 32),
            ],
            'published' => [
                'treeDigest' => $published->digest,
                'fileCount' => $published->fileCount,
                'byteCount' => $published->byteCount,
            ],
            'markers' => [
                'version' => AdminDistSourceMarkerPolicy::VERSION,
                'digest' => $markers->digest(),
                'ids' => $markers->ids(),
            ],
            'acceptance' => [
                'builds' => 2,
                'reproducible' => true,
                'intermediate' => $this->intermediate($intermediateRoot),
                'previousPublishedTreeDigest' => $previous?->digest,
                'changed' => ['added' => $added, 'modified' => $modified, 'removed' => $removed],
                'toolchain' => [
                    'nodePin' => $toolchain['nodePin'],
                    'nodeRuntime' => $toolchain['nodeRuntime'],
                    'npmRuntime' => $toolchain['npmRuntime'],
                ],
            ],
        ]);

        $manifestPath = $root . '/' . AdminDistAcceptanceManifest::PATH;
        $publishedTreeChanged = $previous === null || $previous->digest !== $published->digest;
        $existing = is_file($manifestPath)
            ? AdminDistAcceptanceManifest::fromJson((string) file_get_contents($manifestPath))
            : null;
        // A re-run on identical input must produce zero diff: identical identity
        // over an unchanged tree leaves the committed manifest byte-for-byte
        // alone, so a no-op acceptance never churns the transition record.
        $rewrite = $publishedTreeChanged
            || $existing === null
            || $existing->identityDigest() !== $manifest->identityDigest();
        if ($rewrite) {
            file_put_contents($manifestPath, $manifest->toJson());
        } else {
            $manifest = $existing;
        }

        return new AdminDistAcceptanceResult(
            manifest: $manifest,
            published: $published,
            added: $added,
            modified: $modified,
            removed: $removed,
            publishedTreeChanged: $publishedTreeChanged,
            manifestRewritten: $rewrite,
        );
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} */
    private function transition(?AdminDistTreeInventory $previous, AdminDistTreeInventory $next): array
    {
        $added = [];
        $modified = [];
        $removed = [];
        $before = $previous === null ? [] : $previous->entries;
        foreach ($next->entries as $relative => $meta) {
            if (!isset($before[$relative])) {
                $added[] = $relative;
            } elseif ($before[$relative]['sha256'] !== $meta['sha256']) {
                $modified[] = $relative;
            }
        }
        foreach ($before as $relative => $meta) {
            if (!isset($next->entries[$relative])) {
                $removed[] = $relative;
            }
        }
        sort($added, SORT_STRING);
        sort($modified, SORT_STRING);
        sort($removed, SORT_STRING);

        return [$added, $modified, $removed];
    }

    /** @return array{root: string, artifactCount: int|null, inventoryDigest: string|null} */
    private function intermediate(?string $intermediateRoot): array
    {
        if ($intermediateRoot === null || !is_dir($intermediateRoot)) {
            return ['root' => 'packages/admin/.output', 'artifactCount' => null, 'inventoryDigest' => null];
        }
        $inventory = AdminDistTreeInventory::scan($intermediateRoot, refuseSymlinks: false);

        return [
            'root' => 'packages/admin/.output',
            'artifactCount' => $inventory->fileCount,
            'inventoryDigest' => $inventory->digest,
        ];
    }

    /**
     * Stage the complete build output beside the committed tree, then swap.
     * The published tree is never merged into: the old tree is discarded whole.
     */
    private function replaceWholesale(string $source, string $publishedPath): void
    {
        $parent = dirname($publishedPath);
        $nonce = bin2hex(random_bytes(8));
        $staging = $parent . '/.admin-dist-new-' . $nonce;
        $retired = $parent . '/.admin-dist-old-' . $nonce;
        if (!mkdir($staging, 0o755)) {
            throw new AdminDistAcceptanceException('publish-stage-failed');
        }

        try {
            $this->copyTree($source, $staging, $source);
            if (is_dir($publishedPath)) {
                if (!rename($publishedPath, $retired)) {
                    throw new AdminDistAcceptanceException('publish-replace-failed');
                }
            }
            if (!rename($staging, $publishedPath)) {
                if (is_dir($retired)) {
                    @rename($retired, $publishedPath);
                }
                throw new AdminDistAcceptanceException('publish-replace-failed');
            }
        } finally {
            $this->removeTree($staging);
            $this->removeTree($retired);
        }
    }

    private function copyTree(string $source, string $destination, string $sourceRoot): void
    {
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new AdminDistAcceptanceException('tree-symlink-refused', [$entry->getPathname()]);
            }
            $real = realpath($entry->getPathname());
            if (!is_string($real) || !str_starts_with($real, $sourceRoot . DIRECTORY_SEPARATOR)) {
                throw new AdminDistAcceptanceException('tree-path-escape', [$entry->getPathname()]);
            }
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                if (!mkdir($target, 0o755)) {
                    throw new AdminDistAcceptanceException('publish-stage-failed', [$target]);
                }
                $this->copyTree($real, $target, $sourceRoot);
            } elseif ($entry->isFile()) {
                if (!copy($real, $target) || !chmod($target, 0o644)) {
                    throw new AdminDistAcceptanceException('publish-stage-failed', [$target]);
                }
            } else {
                throw new AdminDistAcceptanceException('tree-entry-invalid', [$entry->getPathname()]);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }

    private function validatedRoot(string $projectRoot): string
    {
        $real = realpath($projectRoot);
        if (!is_string($real) || !is_dir($real)) {
            throw new AdminDistAcceptanceException('project-root-invalid', [$projectRoot]);
        }

        return rtrim($real, DIRECTORY_SEPARATOR);
    }
}
