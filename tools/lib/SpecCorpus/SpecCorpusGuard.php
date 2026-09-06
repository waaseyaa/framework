<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

/**
 * Fail-closed validation for manifest entries, spec paths, and output publication.
 *
 * @api
 */
final class SpecCorpusGuard
{
    public const DOCUMENT_ID_PATTERN = '/^[a-z][a-z0-9_-]*$/';
    public const SPEC_PATH_PATTERN = '#^docs/specs/[a-z0-9][a-z0-9_-]*\\.md$#';

    public static function assertDocumentId(string $id): void
    {
        if (preg_match(self::DOCUMENT_ID_PATTERN, $id) !== 1) {
            throw new SpecCorpusException(
                "Invalid document id '{$id}': must match [a-z][a-z0-9_-]* and cannot contain path separators.",
            );
        }
    }

    public static function assertSpecPath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match(self::SPEC_PATH_PATTERN, $normalized) !== 1) {
            throw new SpecCorpusException(
                "Spec path must be a regular file under docs/specs/: {$path}",
            );
        }
    }

    public static function resolveSpecPath(string $repoRoot, string $path): string
    {
        self::assertSpecPath($path);

        $specsRoot = self::resolveSpecsRoot($repoRoot);

        $absolute = $repoRoot . '/' . str_replace('\\', '/', $path);
        if (is_link($absolute)) {
            throw new SpecCorpusException("Symlink spec paths are not allowed: {$path}");
        }

        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            throw new SpecCorpusException("Spec path does not exist: {$path}");
        }

        $prefix = $specsRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) {
            throw new SpecCorpusException("Spec path escapes docs/specs/: {$path}");
        }

        if (is_link($real)) {
            throw new SpecCorpusException("Symlink spec paths are not allowed: {$path}");
        }

        return $real;
    }

    public static function assertCorpusVersion(string $version): void
    {
        if ($version !== SpecCorpusCompiler::CORPUS_VERSION) {
            throw new SpecCorpusException(
                "Unsupported corpus_version '{$version}'; expected '" . SpecCorpusCompiler::CORPUS_VERSION . "'.",
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{
     *   corpus_version: string,
     *   specs: list<array<string, mixed>>
     * }
     */
    public static function validateManifest(array $manifest): array
    {
        $corpusVersion = $manifest['corpus_version'] ?? SpecCorpusCompiler::CORPUS_VERSION;
        if (!is_string($corpusVersion)) {
            throw new SpecCorpusException('corpus_version must be a string.');
        }
        self::assertCorpusVersion($corpusVersion);

        if (!isset($manifest['specs']) || !is_array($manifest['specs'])) {
            throw new SpecCorpusException('Manifest must contain a specs array.');
        }

        $seenIds = [];
        $specs = [];
        foreach ($manifest['specs'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new SpecCorpusException("Manifest specs[{$index}] must be an object.");
            }
            if (!isset($entry['id'], $entry['path']) || !is_string($entry['id']) || !is_string($entry['path'])) {
                throw new SpecCorpusException("Manifest specs[{$index}] requires string id and path.");
            }

            self::assertDocumentId($entry['id']);
            self::assertSpecPath($entry['path']);

            if (isset($seenIds[$entry['id']])) {
                throw new SpecCorpusException("Duplicate manifest document id '{$entry['id']}'.");
            }
            $seenIds[$entry['id']] = true;
            $specs[] = $entry;
        }

        if ($specs === []) {
            throw new SpecCorpusException('Manifest specs array must not be empty.');
        }

        return [
            'corpus_version' => $corpusVersion,
            'specs' => $specs,
        ];
    }

    public static function resolveOutputDirectory(string $outputDir): string
    {
        if ($outputDir === '' || str_contains($outputDir, "\0")) {
            throw new SpecCorpusException('Invalid output directory.');
        }

        $parent = dirname($outputDir);
        if ($parent === '' || $parent === '.') {
            $parent = getcwd() ?: '.';
        }

        $parentReal = realpath($parent);
        if ($parentReal === false) {
            if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
                throw new SpecCorpusException("Cannot create output parent directory: {$parent}");
            }
            $parentReal = realpath($parent);
        }

        if ($parentReal === false) {
            throw new SpecCorpusException('Output parent directory is not resolvable.');
        }

        $basename = basename(str_replace('\\', '/', $outputDir));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new SpecCorpusException('Invalid output directory name.');
        }

        return $parentReal . DIRECTORY_SEPARATOR . $basename;
    }

    public static function assertOutputTargetAbsent(string $outputDir): void
    {
        if (file_exists($outputDir)) {
            throw new SpecCorpusException(
                'Output target already exists; choose a path that does not exist yet.',
            );
        }
    }

    public static function documentOutputPath(string $documentsDir, string $id): string
    {
        self::assertDocumentId($id);

        $path = $documentsDir . DIRECTORY_SEPARATOR . $id . '.json';
        $documentsReal = realpath($documentsDir) ?: $documentsDir;
        $parentReal = realpath(dirname($path));
        if ($parentReal === false || $parentReal !== $documentsReal) {
            throw new SpecCorpusException("Refusing unsafe document output path for id '{$id}'.");
        }

        return $path;
    }

    private static function resolveSpecsRoot(string $repoRoot): string
    {
        $repoReal = realpath($repoRoot);
        if ($repoReal === false) {
            throw new SpecCorpusException('Repository root is not resolvable.');
        }

        $docsPath = $repoRoot . '/docs';
        $specsPath = $repoRoot . '/docs/specs';
        if (is_link($docsPath) || is_link($specsPath)) {
            throw new SpecCorpusException('docs/specs tree must not be reached through a symlink.');
        }

        $docsReal = realpath($docsPath);
        $specsReal = realpath($specsPath);
        if ($docsReal === false || $specsReal === false || !is_dir($specsReal)) {
            throw new SpecCorpusException('docs/specs directory is missing.');
        }

        $prefix = $repoReal . DIRECTORY_SEPARATOR;
        if (!str_starts_with($docsReal, $prefix) || !str_starts_with($specsReal, $prefix)) {
            throw new SpecCorpusException('docs/specs tree escapes repository root.');
        }

        return $specsReal;
    }
}
