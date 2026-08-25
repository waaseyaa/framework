<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Declared source-contract markers: the named strings a change asserts must be
 * present in the compiled Admin bundle.
 *
 * A marker that is missing — or whose declared value changed without the
 * bundle following — fails acceptance and committed-state verification, so a
 * feature can never ship in source while being absent from the served bytes.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final readonly class AdminDistSourceMarkerPolicy
{
    public const string PATH = 'packages/admin-surface/dist.markers.json';
    public const int VERSION = 1;

    /** @var list<string> */
    private const array SCOPES = ['bundle-js', 'stylesheet', 'published-path'];

    /** @param list<array{id: string, scope: string, value: string}> $markers sorted by id */
    private function __construct(public array $markers) {}

    public static function load(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/\\') . '/' . self::PATH;
        if (!is_file($path)) {
            throw new AdminDistAcceptanceException('source-markers-missing', [self::PATH]);
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AdminDistAcceptanceException('source-markers-unreadable', [self::PATH]);
        }
        if (!is_array($document) || ($document['markersVersion'] ?? null) !== self::VERSION
            || !is_array($document['markers'] ?? null) || $document['markers'] === []) {
            throw new AdminDistAcceptanceException('source-markers-invalid', [self::PATH]);
        }

        $markers = [];
        $seen = [];
        foreach ($document['markers'] as $marker) {
            if (!is_array($marker)) {
                throw new AdminDistAcceptanceException('source-markers-invalid', [self::PATH]);
            }
            $id = $marker['id'] ?? null;
            $scope = $marker['scope'] ?? null;
            $value = $marker['value'] ?? null;
            if (!is_string($id) || $id === '' || !is_string($value) || $value === ''
                || !is_string($scope) || !in_array($scope, self::SCOPES, true) || isset($seen[$id])) {
                throw new AdminDistAcceptanceException('source-markers-invalid', [is_string($id) ? $id : '?']);
            }
            $seen[$id] = true;
            $markers[] = ['id' => $id, 'scope' => $scope, 'value' => $value];
        }
        usort($markers, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return new self($markers);
    }

    public function digest(): string
    {
        return hash('sha256', json_encode(
            ['markersVersion' => self::VERSION, 'markers' => $this->markers],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_column($this->markers, 'id');
    }

    /**
     * Ids whose declared value is not present in the published tree.
     *
     * @return list<string>
     */
    public function unsatisfied(string $distRoot): array
    {
        $real = realpath($distRoot);
        if (!is_string($real) || !is_dir($real)) {
            throw new AdminDistAcceptanceException('tree-root-invalid', [$distRoot]);
        }

        $unsatisfied = [];
        $bundleJs = null;
        $stylesheets = null;
        foreach ($this->markers as $marker) {
            $satisfied = match ($marker['scope']) {
                'published-path' => is_file($real . '/' . $marker['value']),
                'bundle-js' => $this->containedInSomeFile($bundleJs ??= $this->read($real, '.js'), $marker['value']),
                default => $this->containedInSomeFile($stylesheets ??= $this->read($real, '.css'), $marker['value']),
            };
            if (!$satisfied) {
                $unsatisfied[] = $marker['id'];
            }
        }

        return $unsatisfied;
    }

    /**
     * A marker must occur inside ONE compiled file. Matching against a
     * concatenation of the whole tree would let a value be satisfied by a string
     * that only exists because two files were glued together — and directory
     * iteration order is unspecified, so which boundaries exist is not even
     * deterministic.
     *
     * @param list<string> $files
     */
    private function containedInSomeFile(array $files, string $value): bool
    {
        foreach ($files as $contents) {
            if (str_contains($contents, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contents of every file with the given extension, in a deterministic order.
     *
     * @return list<string>
     */
    private function read(string $distRoot, string $extension): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($distRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink() && str_ends_with($file->getFilename(), $extension)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        return array_map(static fn(string $path): string => (string) file_get_contents($path), $paths);
    }
}
