<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Refuses to start the canonical rebuild from an ambiguous Admin source or
 * generated-output boundary (#2524).
 *
 * Modified tracked source is the expected input to a rebuild and is allowed.
 * What is refused is ambiguity that a rebuild would silently bake in:
 * unmerged index entries, unresolved conflict markers, an UNTRACKED file under
 * packages/admin/app (it would compile into the bundle while no committed
 * source reproduces it), a partially staged generated tree, and a generated
 * reference left without the tree it describes.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final class AdminDistWorkspaceGuard
{
    private const string SOURCE_ROOT = 'packages/admin/';
    private const string SOURCE_APP_ROOT = 'packages/admin/app/';
    private const string GENERATED_ROOT = 'packages/admin-surface/dist/';

    /** @var list<string> */
    private const array GENERATED_FILES = [
        'packages/admin-surface/dist.signature',
        'packages/admin-surface/dist.manifest.json',
        'packages/admin-surface/dist.markers.json',
    ];

    /** @var list<string> */
    private const array UNMERGED_CODES = ['DD', 'AU', 'UD', 'UA', 'DU', 'AA', 'UU'];

    /** @var list<string> */
    private const array SCANNED_EXTENSIONS = [
        'css', 'html', 'js', 'json', 'mjs', 'cjs', 'map', 'svg', 'ts', 'tsx', 'txt', 'vue', 'yaml', 'yml', 'signature',
    ];

    private const int SCAN_SIZE_LIMIT = 8 * 1024 * 1024;

    /**
     * @param list<string> $porcelain `git status --porcelain` lines
     *
     * @throws AdminDistAcceptanceException
     */
    public function assertAcceptable(string $projectRoot, array $porcelain): void
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new AdminDistAcceptanceException('project-root-invalid', [$projectRoot]);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        $sourceUnmerged = [];
        $generatedUnmerged = [];
        $untracked = [];
        $partiallyStaged = [];
        foreach ($porcelain as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $code = substr($line, 0, 2);
            $path = $this->pathOf(substr($line, 3));
            $generated = $this->isGenerated($path);
            $source = str_starts_with($path, self::SOURCE_ROOT);
            if (!$generated && !$source) {
                continue;
            }
            if (in_array($code, self::UNMERGED_CODES, true)) {
                $generated ? $generatedUnmerged[] = $path : $sourceUnmerged[] = $path;
                continue;
            }
            if ($code === '??') {
                if (str_starts_with($path, self::SOURCE_APP_ROOT)) {
                    $untracked[] = $path;
                }
                continue;
            }
            if ($generated && $code[0] !== ' ' && $code[1] !== ' ') {
                $partiallyStaged[] = $path;
            }
        }

        if ($sourceUnmerged !== []) {
            throw new AdminDistAcceptanceException('admin-source-unmerged', $sourceUnmerged);
        }
        if ($generatedUnmerged !== []) {
            throw new AdminDistAcceptanceException('generated-output-unmerged', $generatedUnmerged);
        }
        if ($untracked !== []) {
            throw new AdminDistAcceptanceException('admin-source-untracked', $untracked);
        }
        if ($partiallyStaged !== []) {
            throw new AdminDistAcceptanceException('generated-output-partially-staged', $partiallyStaged);
        }

        $this->assertGeneratedBoundaryComplete($root);
        $this->assertNoConflictMarkers($root);
    }

    private function assertGeneratedBoundaryComplete(string $root): void
    {
        $published = is_dir($root . '/' . rtrim(self::GENERATED_ROOT, '/'));
        $signature = is_file($root . '/' . self::GENERATED_FILES[0]);
        if ($published !== $signature) {
            throw new AdminDistAcceptanceException('generated-output-incomplete', [
                $published ? 'dist.signature' : rtrim(self::GENERATED_ROOT, '/'),
            ]);
        }
    }

    private function assertNoConflictMarkers(string $root): void
    {
        $offenders = [];
        foreach ([rtrim(self::GENERATED_ROOT, '/'), rtrim(self::SOURCE_APP_ROOT, '/')] as $relative) {
            $directory = $root . '/' . $relative;
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile() && !$entry->isLink() && $this->isScannable($entry->getPathname())
                    && $this->hasConflictMarkers($entry->getPathname())) {
                    $offenders[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                }
            }
        }
        foreach (self::GENERATED_FILES as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path) && $this->hasConflictMarkers($path)) {
                $offenders[] = $relative;
            }
        }
        if ($offenders !== []) {
            sort($offenders, SORT_STRING);
            throw new AdminDistAcceptanceException('conflict-markers-present', $offenders);
        }
    }

    private function isScannable(string $path): bool
    {
        $size = filesize($path);
        if (!is_int($size) || $size > self::SCAN_SIZE_LIMIT) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SCANNED_EXTENSIONS, true);
    }

    private function hasConflictMarkers(string $path): bool
    {
        $contents = @file_get_contents($path);

        return is_string($contents)
            && preg_match('/^(?:<{7}|={7}|>{7})(?:[ \t].*)?$/m', $contents) === 1;
    }

    private function isGenerated(string $path): bool
    {
        return str_starts_with($path, self::GENERATED_ROOT) || in_array($path, self::GENERATED_FILES, true);
    }

    private function pathOf(string $raw): string
    {
        $raw = trim($raw);
        $arrow = strpos($raw, ' -> ');
        if ($arrow !== false) {
            $raw = substr($raw, $arrow + 4);
        }

        return trim($raw, '"');
    }
}
