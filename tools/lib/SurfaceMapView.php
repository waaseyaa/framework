<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/**
 * Deterministic rendering of the two derived surface-map views
 * (docs/specs/public-surface-declarations.md §6, FW-DELIVERY-SURFACE-01).
 *
 * Shared by bin/generate-surface-map (which writes/checks/prints the views)
 * and tools/check-surface-parity.php (which needs the SAME bytes to enforce
 * the §6 tracked/generated boundary rule without re-implementing rendering).
 */
final class SurfaceMapView
{
    /** @var array<int, string> */
    private const LAYER_NAMES = [
        0 => 'Foundation',
        1 => 'Core Data',
        2 => 'Content Types',
        3 => 'Services',
        4 => 'API',
        5 => 'AI',
        6 => 'Interfaces',
    ];

    private const UNASSIGNED_LAYER = 999;

    /**
     * Parses the layer table out of bin/check-package-layers' own source —
     * always from $selfRoot (this repository), never from a --root fixture
     * tree, which has no such script. Fails closed if the table shape no
     * longer parses as expected (fewer than 40 packages found).
     *
     * @return array<string, int> package short name => layer number
     */
    public static function layerTable(string $selfRoot): array
    {
        $layerScriptPath = $selfRoot . '/bin/check-package-layers';
        if (!is_file($layerScriptPath)) {
            throw new RuntimeException("cannot find the layer table at {$layerScriptPath}.");
        }
        $source = (string) file_get_contents($layerScriptPath);
        preg_match_all("/^\\s*'([a-z0-9-]+)'\\s*=>\\s*([0-9]+),\$/m", $source, $matches, PREG_SET_ORDER);
        if (count($matches) < 40) {
            throw new RuntimeException(sprintf(
                'parsed only %d package(s) from the layer table in %s (expected at least 40) — the table shape may have changed; regex needs updating.',
                count($matches),
                $layerScriptPath,
            ));
        }
        $layerByShort = [];
        foreach ($matches as $match) {
            $layerByShort[$match[1]] = (int) $match[2];
        }

        return $layerByShort;
    }

    /**
     * @param array<string, string> $composedMap
     * @param array<string, array{entries: list<array{fqcn: string, disposition: string, purpose: ?string, ref: ?string}>, notes: list<string>, prefixes: list<string>}> $packages
     * @param array<string, int> $layerByShort
     *
     * @return array{0: string, 1: string} [phpView, mdView]
     */
    public static function render(array $composedMap, array $packages, array $layerByShort, SurfaceScanner $scanner): array
    {
        $phpLines = [];
        $phpLines[] = '<?php';
        $phpLines[] = '';
        $phpLines[] = '// GENERATED FILE — DO NOT EDIT BY HAND.';
        $phpLines[] = '// Composed by bin/generate-surface-map from packages/<pkg>/public-surface.php';
        $phpLines[] = '// declarations. Contract: docs/specs/public-surface-declarations.md.';
        $phpLines[] = '// Format: \'Fully\\Qualified\\ClassName\' => \'public|internal|extract|remove\'.';
        $phpLines[] = '';
        $phpLines[] = 'declare(strict_types=1);';
        $phpLines[] = '';
        $phpLines[] = 'return [';
        foreach ($composedMap as $fqcn => $disposition) {
            // Single backslashes, as the hand-authored map wrote them: a
            // single-quoted PHP string needs no escaping for `\F`, and regex
            // readers of the tracked view (audit inventories) then see real
            // FQCNs rather than doubled separators. FQCNs cannot contain quotes.
            $phpLines[] = sprintf("    '%s' => '%s',", str_replace("'", "\\'", $fqcn), $disposition);
        }
        $phpLines[] = '];';
        $phpLines[] = '';
        $phpView = implode("\n", $phpLines);

        // Group owning packages by layer, then package name.
        $byLayer = [];
        foreach ($packages as $short => $package) {
            if ($package['entries'] === [] && $package['notes'] === []) {
                continue;
            }
            $layer = $layerByShort[$short] ?? self::UNASSIGNED_LAYER;
            $byLayer[$layer][$short] = $package;
        }
        ksort($byLayer, SORT_NUMERIC);

        $mdLines = [];
        $mdLines[] = '# Waaseyaa Public Surface Map';
        $mdLines[] = '';
        $mdLines[] = 'GENERATED FILE — DO NOT EDIT BY HAND.';
        $mdLines[] = '';
        $mdLines[] = 'This document lists every governed API element in the Waaseyaa framework with its';
        $mdLines[] = 'declared disposition. Only `public` rows are stability commitments; `internal` rows,';
        $mdLines[] = 'and any element not listed here, are `@internal` and may change without notice.';
        $mdLines[] = '';
        $mdLines[] = 'Single editable authority: `packages/<pkg>/public-surface.php` (contract:';
        $mdLines[] = '`docs/specs/public-surface-declarations.md`). Composed by `bin/generate-surface-map`.';
        $mdLines[] = 'Machine-readable derived view: `docs/public-surface-map.php`.';
        $mdLines[] = '';
        $mdLines[] = '---';
        foreach ($byLayer as $layer => $packagesInLayer) {
            $layerName = self::LAYER_NAMES[$layer] ?? 'Unassigned';
            $mdLines[] = '';
            $mdLines[] = "## Layer {$layer}: {$layerName}";
            ksort($packagesInLayer, SORT_STRING);
            foreach ($packagesInLayer as $short => $package) {
                $mdLines[] = '';
                $mdLines[] = "### {$short}";
                $entries = $package['entries'];
                usort($entries, static fn(array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));
                if ($entries !== []) {
                    $mdLines[] = '';
                    $mdLines[] = '| Element | Type | Disposition | Purpose |';
                    $mdLines[] = '|---------|------|-------------|---------|';
                    foreach ($entries as $entry) {
                        $element = $entry['fqcn'];
                        foreach ($package['prefixes'] as $prefix) {
                            if ($prefix !== '' && str_starts_with($element, $prefix)) {
                                $element = substr($element, strlen($prefix));
                                break;
                            }
                        }
                        $type = $scanner->shape($entry['fqcn']) ?? 'unknown';
                        $purpose = $entry['purpose'] ?? '—';
                        $mdLines[] = sprintf('| `%s` | %s | %s | %s |', $element, $type, $entry['disposition'], $purpose);
                    }
                }
                if ($package['notes'] !== []) {
                    $mdLines[] = '';
                    foreach ($package['notes'] as $note) {
                        $mdLines[] = "- {$note}";
                    }
                }
            }
        }
        $mdLines[] = '';
        $mdView = implode("\n", $mdLines);

        return [$phpView, $mdView];
    }
}
