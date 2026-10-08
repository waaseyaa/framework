<?php

declare(strict_types=1);

/**
 * Forensic layer audit artifact generator. Run from repo root:
 *   php tools/audit/GenerateLayerAudit.php        # default layer 1
 *   php tools/audit/GenerateLayerAudit.php 2         # content types
 */
require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\ParserFactory;

$targetLayer = 1;
if (isset($argv[1]) && $argv[1] !== '' && is_numeric($argv[1])) {
    $targetLayer = (int) $argv[1];
}
if ($targetLayer < 0 || $targetLayer > 6) {
    fwrite(STDERR, "Layer must be between 0 and 6.\n");
    exit(2);
}

$layerNames = [
    0 => 'Foundation',
    1 => 'Core Data',
    2 => 'Content Types',
    3 => 'Services',
    4 => 'API',
    5 => 'AI',
    6 => 'Interfaces',
];
$layerName = $layerNames[$targetLayer] ?? "Layer {$targetLayer}";

$root = realpath(__DIR__ . '/../..');
$outTag = "layer{$targetLayer}-audit";
$outDir = $root . '/build/' . $outTag;
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$layerByShort = loadLayerMap($root);
$packageShorts = array_keys(array_filter($layerByShort, static fn (int $l): bool => $l === $targetLayer));
sort($packageShorts);

$nsTopToShort = buildNsTopToShort($root, $layerByShort);

$parser = (new ParserFactory())->createForNewestSupportedVersion();

$publicApi = [
    'generated_at' => gmdate('c'),
    'source' => 'bin/check-package-layers LAYER_BY_SHORT',
    'layer' => $targetLayer,
    'packages' => [],
];

$metadataFindings = [
    'generated_at' => gmdate('c'),
    'service_providers' => [],
    'subscribers' => [],
    'policy_or_gate_attributes' => [],
];

$useViolations = [];

foreach ($packageShorts as $short) {
    $srcDir = $root . "/packages/{$short}/src";
    $publicApi['packages'][$short] = ['file_count' => 0, 'symbols' => []];
    if (!is_dir($srcDir)) {
        $publicApi['packages'][$short]['error'] = 'no src';
        continue;
    }
    $fileCount = 0;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $fileCount++;
        $path = $file->getPathname();
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
        $code = (string) file_get_contents($path);
        try {
            $ast = $parser->parse($code);
        } catch (Throwable) {
            $publicApi['packages'][$short]['parse_errors'][] = $rel;
            continue;
        }
        if ($ast === null) {
            continue;
        }
        $fileNs = null;
        $stmts = $ast;
        foreach ($ast as $top) {
            if ($top instanceof Namespace_) {
                $fileNs = $top->name !== null ? (string) $top->name : '';
                $stmts = $top->stmts ?? [];
                break;
            }
        }
        staticScanUses(
            $code,
            $rel,
            $short,
            $nsTopToShort,
            $layerByShort,
            $useViolations,
            $targetLayer
        );
        foreach ($stmts as $s) {
            if (!$s instanceof ClassLike) {
                continue;
            }
            if ($s->name === null) {
                continue;
            }
            $className = $fileNs !== null && $fileNs !== '' ? $fileNs . '\\' . $s->name->name : $s->name->name;
            if ($s instanceof Class_) {
                if ($s->isAbstract()) {
                    $kind = 'abstract_class';
                } elseif (classHasPhpAttribute($s, 'Attribute')) {
                    $kind = 'attribute_class';
                } else {
                    $kind = 'class';
                }
                if (str_ends_with($s->name->name, 'ServiceProvider')) {
                    $metadataFindings['service_providers'][] = ['package' => $short, 'file' => $rel, 'class' => $className];
                }
                if (str_ends_with($s->name->name, 'Listener')) {
                    $metadataFindings['subscribers'][] = ['package' => $short, 'file' => $rel, 'class' => $className];
                }
            } elseif ($s instanceof Interface_) {
                $kind = 'interface';
            } elseif ($s instanceof Trait_) {
                $kind = 'trait';
            } elseif ($s instanceof Enum_) {
                $kind = 'enum';
            } else {
                $kind = 'class';
            }
            $classDoc = $s->getDocComment()?->getText() ?? '';
            $row = [
                'fqcn' => $className,
                'kind' => $kind,
                'file' => $rel,
                'line' => (int) $s->getStartLine(),
                'internal' => str_contains($classDoc, '@internal'),
                'api' => preg_match('/@api\b/i', (string) $classDoc) === 1,
            ];
            if ($kind === 'attribute_class' || str_ends_with($s->name->name, 'Attribute')) {
                $metadataFindings['policy_or_gate_attributes'][] = [
                    'package' => $short, 'file' => $rel, 'class' => $className, 'note' => 'review_if_contract',
                ];
            }
            $publicApi['packages'][$short]['symbols'][] = $row;
        }
    }
    $publicApi['packages'][$short]['file_count'] = $fileCount;
}

$pk = "public_api_layer{$targetLayer}.json";
file_put_contents(
    $outDir . '/' . $pk,
    json_encode($publicApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$checkLayersOutput = (string) shell_exec('cd ' . escapeshellarg($root) . ' && bin/check-package-layers 2>&1');
$reqDevOutput = (string) shell_exec('cd ' . escapeshellarg($root) . ' && bin/audit-require-dev-layers 2>&1');

$agentsDrift = buildAgentsMdDrift($root, $targetLayer, $packageShorts);
$vKey = "l{$targetLayer}_waaseyaa_use_violations";
$dedupedV = dedupeViolationRows($useViolations);
$boundaryReport = [
    'generated_at' => gmdate('c'),
    "layer_{$targetLayer}_packages" => $packageShorts,
    'agents_md_drift' => $agentsDrift,
    'bin_check_package_layers' => [
        'exit_code' => 0,
        'output' => trim($checkLayersOutput),
    ],
    'bin_audit_require_dev_layers' => [
        'output' => trim($reqDevOutput),
    ],
    $vKey => $dedupedV,
    'summary' => [
        "l{$targetLayer}_static_use_violation_count" => \count($dedupedV),
    ],
];
$brName = "layer{$targetLayer}_layer_boundary_report.json";
file_put_contents(
    $outDir . '/' . $brName,
    json_encode($boundaryReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$mdName = "layer{$targetLayer}_metadata_consistency.json";
file_put_contents(
    $outDir . '/' . $mdName,
    json_encode($metadataFindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

echo "Wrote public_api, boundary, metadata to {$outDir}\n";

$inventory = buildLayerInventory($root, $targetLayer, $packageShorts);
$testMap = buildSymbolTestMap($root, $packageShorts, $publicApi);
$stmName = "symbol_test_map_layer{$targetLayer}.json";
file_put_contents(
    $outDir . '/' . $stmName,
    json_encode($testMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$hygieneText = runHygieneScan($root, $targetLayer, $packageShorts);
$hygName = "layer{$targetLayer}_hygiene_report.txt";
file_put_contents($outDir . '/' . $hygName, $hygieneText);

$phpstanPaths = array_map(static fn (string $s): string => $root . "/packages/{$s}/src", $packageShorts);
$phpstanCmd = escapeshellarg($root . '/vendor/bin/phpstan') . ' analyse --no-progress --memory-limit=512M --error-format=json ' . implode(' ', array_map('escapeshellarg', $phpstanPaths)) . ' 2>&1';
$phpstanRaw = (string) shell_exec("cd " . escapeshellarg($root) . " && " . $phpstanCmd);
$phpstanJsonOnly = extractJsonObject($phpstanRaw);
$neonInclusion = detectPhpstanNeonLayerInclusion($root, $packageShorts);
$staticAnalysis = [
    'generated_at' => gmdate('c'),
    'phpstan' => [
        'command' => "phpstan analyse (L{$targetLayer} package src/ trees only — not full CI set)",
        'paths' => $phpstanPaths,
    ],
    'ci_alignment_note' => 'Default `composer phpstan` uses the full `phpstan.neon` path list. This audit run is scoped to this layer\'s package src/ directories. Compare `phpstan_neon.packages_in_neon` to see which of those dirs are also in `phpstan.neon`.',
    'phpstan_baseline' => is_file($root . '/phpstan-baseline.neon') ? 'present' : 'absent',
    'phpstan_neon' => [
        'note' => 'Per-package: path contains packages/<short>/src in phpstan.neon.',
        'packages_in_neon' => $neonInclusion,
    ],
    'raw_stdout' => $phpstanRaw,
    'phpstan_json_parsed' => $phpstanJsonOnly,
];
$saName = "layer{$targetLayer}_static_analysis.json";
file_put_contents(
    $outDir . '/' . $saName,
    json_encode($staticAnalysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$phpstanData = \is_array($phpstanJsonOnly) ? $phpstanJsonOnly : json_decode($phpstanRaw, true);
$totals = \is_array($phpstanData) ? ($phpstanData['totals'] ?? null) : null;
$deliverableNames = [
    "layer{$targetLayer}-audit.md",
    "layer{$targetLayer}-audit.json",
    $pk,
    $stmName,
    $brName,
    $mdName,
    $hygName,
    $saName,
];
$mainAudit = [
    'generated_at' => gmdate('c'),
    'audit' => "Layer {$targetLayer} forensic audit ({$layerName})",
    'target_layer' => $targetLayer,
    'layer_name' => $layerName,
    'canonical_layer_packages' => $packageShorts,
    'inventory' => $inventory,
    'priority_findings' => buildPriorityFindings(
        $boundaryReport,
        $testMap,
        $staticAnalysis,
        $targetLayer,
        $stmName
    ),
    'phpstan_totals' => $totals,
    'deliverables' => $deliverableNames,
];
$jaName = "layer{$targetLayer}-audit.json";
file_put_contents(
    $outDir . '/' . $jaName,
    json_encode($mainAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

$mdOut = buildMarkdownReport(
    $root,
    $targetLayer,
    $layerName,
    $outTag,
    $mainAudit,
    $boundaryReport,
    $testMap,
    $staticAnalysis,
    $metadataFindings
);
file_put_contents($outDir . "/layer{$targetLayer}-audit.md", $mdOut);

echo "Wrote symbol_test_map, hygiene, static, layer{$targetLayer}-audit.json, layer{$targetLayer}-audit.md to {$outDir}\n";

/**
 * @param list<string> $packageShorts
 * @return array<string, mixed>
 */
function buildLayerInventory(string $root, int $targetLayer, array $packageShorts): array
{
    $out = [
        'generated_at' => gmdate('c'),
        'layer' => $targetLayer,
        'packages' => [],
    ];
    foreach ($packageShorts as $short) {
        $manifest = $root . "/packages/{$short}/composer.json";
        $req = [];
        $reqd = [];
        if (is_file($manifest)) {
            $j = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            foreach ($j['require'] ?? [] as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'waaseyaa/')) {
                    $req[$k] = $v;
                }
            }
            foreach ($j['require-dev'] ?? [] as $k => $v) {
                if (is_string($k) && str_starts_with($k, 'waaseyaa/')) {
                    $reqd[$k] = $v;
                }
            }
        }
        $src = $root . "/packages/{$short}/src";
        $td = $root . "/packages/{$short}/tests";
        $out['packages'][$short] = [
            'waaseyaa_require' => $req,
            'waaseyaa_require_dev' => $reqd,
            'src_php_count' => is_dir($src) ? countPhpFilesInTree($src) : 0,
            'has_tests_unit_dir' => is_dir($td . '/Unit'),
            'has_tests_integration_dir' => is_dir($td . '/Integration'),
        ];
    }

    return $out;
}

function countPhpFilesInTree(string $dir): int
{
    $n = 0;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $n++;
        }
    }

    return $n;
}

/**
 * @param list<string>         $packageShorts
 * @param array<string, mixed> $publicApi
 * @return array<string, mixed>
 */
function buildSymbolTestMap(string $root, array $packageShorts, array $publicApi): array
{
    $perSymbol = [];
    $scanned = 0;
    foreach ($packageShorts as $short) {
        $td = $root . "/packages/{$short}/tests";
        if (!is_dir($td)) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($td, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $content = (string) file_get_contents($file->getPathname());
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $register = static function (string $fqcn) use (&$perSymbol, $rel): void {
                if (!str_starts_with($fqcn, 'Waaseyaa\\')) {
                    return;
                }
                if (!isset($perSymbol[$fqcn])) {
                    $perSymbol[$fqcn] = [];
                }
                $perSymbol[$fqcn][] = $rel;
            };

            if (preg_match_all('/@covers\s*\\\\?([A-Za-z0-9_\\\\]+)/u', $content, $m)) {
                foreach ($m[1] as $raw) {
                    if (\is_string($raw) && $raw !== '') {
                        $register(ltrim($raw, '\\'));
                    }
                }
            }

            // Convention C1 (mission #1335): #[CoversClass(\Foo\Bar::class)] attributes (PHPUnit 10.5+).
            // Resolve short class refs via the file's use statements.
            $useMap = [];
            if (preg_match_all('/^\s*use\s+(?!function\s|const\s)(\\\\?[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $content, $um)) {
                foreach ($um[1] as $i => $fqcn) {
                    if (!\is_string($fqcn) || $fqcn === '') {
                        continue;
                    }
                    $alias = (string) ($um[2][$i] ?? '');
                    if ($alias === '') {
                        $parts = explode('\\', ltrim($fqcn, '\\'));
                        $alias = (string) end($parts);
                    }
                    $useMap[$alias] = ltrim($fqcn, '\\');
                }
            }
            if (preg_match_all('/#\[CoversClass\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class\s*\)\]/u', $content, $am)) {
                foreach ($am[1] as $rawRef) {
                    if (!\is_string($rawRef) || $rawRef === '') {
                        continue;
                    }
                    $fqcn = str_contains($rawRef, '\\')
                        ? ltrim($rawRef, '\\')
                        : ($useMap[$rawRef] ?? null);
                    if ($fqcn !== null) {
                        $register($fqcn);
                    }
                }
            }
        }
    }
    foreach (array_keys($perSymbol) as $sym) {
        $perSymbol[$sym] = array_values(array_unique($perSymbol[$sym]));
    }

    $missing = [];
    foreach ($publicApi['packages'] as $data) {
        foreach ($data['symbols'] ?? [] as $sym) {
            if (($sym['internal'] ?? false) === true) {
                continue;
            }
            $k = (string) ($sym['fqcn'] ?? '');
            $kind = (string) ($sym['kind'] ?? '');
            if (!\in_array($kind, ['class', 'interface', 'enum', 'abstract_class', 'attribute_class'], true)) {
                continue;
            }
            if ($k === '') {
                continue;
            }
            if (!isset($perSymbol[$k])) {
                $missing[] = $sym;
            }
        }
    }

    return [
        'generated_at' => gmdate('c'),
        'test_files_parsed' => $scanned,
        'at_covers_hits' => \count($perSymbol),
        'per_symbol' => $perSymbol,
        'public_non_internal_symbols_lacking_at_covers' => $missing,
        'methodology' => '@covers PHPDoc and #[CoversClass(...)] attributes are both indexed (Convention C1, mission #1335). Short refs resolved via file-local use statements; same-namespace refs without an alias are skipped.',
    ];
}

/**
 * @param list<string> $packageShorts
 */
function runHygieneScan(string $root, int $targetLayer, array $packageShorts): string
{
    $lines = ["Layer {$targetLayer} hygiene scan (this layer src/ trees only)", 'generated_at: ' . gmdate('c'), ''];
    $hasRg = trim((string) shell_exec('command -v rg 2>/dev/null || true'));
    if ($hasRg === '') {
        $lines[] = 'ripgrep (rg) not on PATH; using PHP fallback (slower).';
        $lines = array_merge($lines, phpFallbackHygiene($root, $packageShorts));

        return implode("\n", $lines) . "\n";
    }
    $pathArgs = array_map(
        static fn (string $s): string => escapeshellarg($root . "/packages/{$s}/src"),
        $packageShorts
    );
    $pattern = 'TODO|FIXME|HACK|eval\s*\(|unserialize\s*\(|catch\s*\(\s*Throwable|catch\s*\(\s*Exception|new\s+Reflection';
    $cmd = 'rg -n ' . escapeshellarg($pattern) . ' ' . implode(' ', $pathArgs) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    if ($out === '') {
        $lines[] = 'No pattern matches in rg, or no output.';
    } else {
        $lines[] = $out;
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<string> $packageShorts
 * @return list<string>
 */
function phpFallbackHygiene(string $root, array $packageShorts): array
{
    $rx = ['TODO', 'FIXME', 'HACK', 'eval', 'unserialize', 'catch (Throwable', 'catch (Exception', 'new Reflection'];
    $out = [];
    foreach ($packageShorts as $short) {
        $src = $root . "/packages/{$short}/src";
        if (!is_dir($src)) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $c = (string) file_get_contents($file->getPathname());
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            foreach (explode("\n", $c) as $i => $line) {
                $num = $i + 1;
                foreach ($rx as $needle) {
                    if (str_contains($line, $needle)) {
                        $out[] = "{$rel}:{$num}:{$line}";
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * @return list<string>|null
 */
function parseAgentsMdLayerPackages(string $root, int $layer): ?array
{
    $md = (string) @file_get_contents($root . '/AGENTS.md');
    if (!preg_match('/^\|\s*' . $layer . '\s*\|[^|]+\|\s*([^|]+?)\s*\|/m', $md, $m)) {
        return null;
    }
    $cell = trim($m[1]);
    $parts = array_map('trim', explode(',', $cell));

    return array_values(array_filter($parts, static fn (string $x): bool => $x !== ''));
}

/**
 * @param list<string> $scriptPackages
 * @return array<string, mixed>
 */
function buildAgentsMdDrift(string $root, int $targetLayer, array $scriptPackages): array
{
    $agents = parseAgentsMdLayerPackages($root, $targetLayer);
    if ($agents === null) {
        return [
            'agents_parsed' => false,
            'in_script_not_in_agents' => [],
            'in_agents_not_in_script' => [],
        ];
    }
    $scriptSet = array_values(array_unique($scriptPackages));
    sort($agents);
    sort($scriptSet);

    return [
        'agents_parsed' => true,
        'agents_table_packages' => $agents,
        'script_layer_packages' => $scriptSet,
        'in_script_not_in_agents' => array_values(array_diff($scriptSet, $agents)),
        'in_agents_not_in_script' => array_values(array_diff($agents, $scriptSet)),
    ];
}

/**
 * @return array<string, int>
 */
function loadLayerMap(string $root): array
{
    $script = (string) file_get_contents($root . '/bin/check-package-layers');
    if (!preg_match('/LAYER_BY_SHORT = \{([^}]+)\}/s', $script, $m)) {
        throw new RuntimeException('Could not parse LAYER_BY_SHORT');
    }
    $body = $m[1];
    $map = [];
    if (preg_match_all('/"([a-z0-9-]+)"\s*:\s*(\d+)\s*,?/i', $body, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $row) {
            $map[$row[1]] = (int) $row[2];
        }
    }
    if ($map === []) {
        throw new RuntimeException('Empty layer map');
    }

    return $map;
}

/**
 * @param array<string, int> $layerByShort
 * @return array<string, string> top NS segment (e.g. Entity) -> short
 */
function buildNsTopToShort(string $root, array $layerByShort): array
{
    $nsTopToShort = [];
    foreach (glob($root . '/packages/*/composer.json') as $manifestPath) {
        $j = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $name = (string) ($j['name'] ?? '');
        if (!preg_match('#^waaseyaa/([a-z0-9-]+)$#', $name, $m)) {
            continue;
        }
        $short = $m[1];
        $al = $j['autoload']['psr-4'] ?? [];
        if (!\is_array($al)) {
            continue;
        }
        foreach (array_keys($al) as $prefix) {
            if (!\is_string($prefix) || !str_starts_with($prefix, 'Waaseyaa\\')) {
                continue;
            }
            $rest = substr($prefix, 9);
            $top = explode('\\', $rest, 2)[0] ?? '';
            if ($top !== '') {
                $nsTopToShort[$top] = $short;
            }
        }
    }

    return $nsTopToShort;
}

/**
 * @return array<string, mixed>|null
 */
function extractJsonObject(string $raw): ?array
{
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $j = json_decode(substr($raw, $start, $end - $start + 1), true);
    if (!\is_array($j)) {
        return null;
    }

    return $j;
}

function classHasPhpAttribute(Class_ $c, string $basename): bool
{
    foreach ($c->attrGroups as $g) {
        foreach ($g->attrs as $a) {
            $n = $a->name->toString();
            if ($n === $basename
                || $n === '\\' . $basename
                || $n === 'Attribute'
                || str_ends_with($n, '\\' . $basename)
            ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, int>  $layerByShort
 * @param array<int, string>  $violations
 */
function staticScanUses(
    string $code,
    string $rel,
    string $fromShort,
    array $nsTopToShort,
    array $layerByShort,
    array &$violations,
    int $targetLayer
): void {
    if ($targetLayer === 0 && isLayer0KernelOrRouterCompositionUseExempt($rel)) {
        return;
    }
    if (!preg_match_all(
        '/^\s*use\s+(Waaseyaa\\\\[A-Za-z0-9_\\\\]+)\s*;/m',
        $code,
        $m
    )) {
        return;
    }
    $used = $m[1];
    foreach ($used as $fq) {
        $top = getNsTop($fq);
        if ($top === null) {
            continue;
        }
        $toShort = $nsTopToShort[$top] ?? null;
        if ($toShort === null) {
            continue;
        }
        if ($toShort === $fromShort) {
            continue;
        }
        $layer = $layerByShort[$toShort] ?? -1;
        if ($layer > $targetLayer) {
            $violations[] = [
                'file' => $rel,
                'from_package' => $fromShort,
                'import' => $fq,
                'target_package' => $toShort,
                'target_layer' => $layer,
                'rule' => "L{$targetLayer} must not use types from layer >{$targetLayer} via use statement",
            ];
        }
    }
}

/**
 * AGENTS.md documents Kernel/ and composition-root HTTP routers in Foundation as intentional
 * cross-layer wiring; exclude them from static `use` violation noise for Layer 0.
 */
function isLayer0KernelOrRouterCompositionUseExempt(string $rel): bool
{
    return str_contains($rel, 'packages/foundation/src/Kernel/')
        || str_contains($rel, 'packages/foundation/src/Http/Router/');
}

function getNsTop(string $useStmtFqcn): ?string
{
    if (!str_starts_with($useStmtFqcn, 'Waaseyaa\\')) {
        return null;
    }
    $rest = substr($useStmtFqcn, 9);
    if ($rest === false || $rest === '') {
        return null;
    }
    $parts = explode('\\', $rest);

    return $parts[0] !== '' ? $parts[0] : null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function dedupeViolationRows(array $rows): array
{
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $k = json_encode($r, JSON_THROW_ON_ERROR);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $r;
    }

    return $out;
}

/**
 * @param list<string> $packageShorts
 * @return array{included: list<string>, missing_from_phpstan_neon: list<string>}
 */
function detectPhpstanNeonLayerInclusion(string $root, array $packageShorts): array
{
    $neon = (string) @file_get_contents($root . '/phpstan.neon');
    $included = [];
    $missing = [];
    foreach ($packageShorts as $s) {
        $needle = 'packages/' . $s . '/src';
        if (str_contains($neon, $needle)) {
            $included[] = $s;
        } else {
            $missing[] = $s;
        }
    }

    return ['included' => $included, 'missing_from_phpstan_neon' => $missing];
}

/**
 * @return list<array<string, mixed>>
 */
function buildPriorityFindings(
    array $boundary,
    array $testMap,
    array $staticAnalysis,
    int $targetLayer,
    string $symbolMapFile
): array {
    $f = [];
    $id = 0;
    $vKey = "l{$targetLayer}_waaseyaa_use_violations";
    foreach ($boundary[$vKey] ?? [] as $v) {
        $id++;
        $f[] = [
            'id' => "L{$targetLayer}-USE-{$id}",
            'priority' => 2,
            'category' => 'layer_boundary',
            'severity' => 'high',
            'message' => "L{$targetLayer} source uses Waaseyaa type from higher layer (static use)",
            'detail' => $v,
        ];
    }
    $neonM = $staticAnalysis['phpstan_neon']['packages_in_neon']['missing_from_phpstan_neon'] ?? [];
    foreach ((array) $neonM as $short) {
        $f[] = [
            'id' => "L{$targetLayer}-PHPSTAN-GAP-{$short}",
            'priority' => 2,
            'category' => 'ci_alignment',
            'severity' => 'medium',
            'message' => "Package '{$short}' (L{$targetLayer}) not listed in phpstan.neon paths; default CI may not analyze it.",
        ];
    }
    $missingCov = (array) ($testMap['public_non_internal_symbols_lacking_at_covers'] ?? []);
    if ($missingCov !== []) {
        $sample = \array_slice($missingCov, 0, 12);
        $f[] = [
            'id' => "L{$targetLayer}-COV-AGG",
            'priority' => 4,
            'category' => 'test_coverage',
            'severity' => 'low',
            'message' => "Public (non-@internal) L{$targetLayer} symbols with no @covers / #[CoversClass] in this layer's test tree (count). Full list: {$symbolMapFile}",
            'detail' => [
                'count' => \count($missingCov),
                'sample_fqcn' => array_map(
                    static fn (array $r): string => (string) ($r['fqcn'] ?? ''),
                    $sample
                ),
            ],
        ];
    }
    $d = (array) ($boundary['agents_md_drift'] ?? []);
    if (($d['agents_parsed'] ?? false) === true) {
        $a = (array) ($d['in_script_not_in_agents'] ?? []);
        $b = (array) ($d['in_agents_not_in_script'] ?? []);
        if ($a !== [] || $b !== []) {
            $f[] = [
                'id' => "L{$targetLayer}-DOC-AGENTS",
                'priority' => 1,
                'category' => 'public_api_documentation',
                'severity' => 'low',
                'message' => "AGENTS.md Layer {$targetLayer} table and bin/check-package-layers disagree on package set.",
                'detail' => [
                    'in_script_not_in_agents' => $a,
                    'in_agents_not_in_script' => $b,
                ],
            ];
        }
    }

    return $f;
}

/**
 * @param array<string, mixed> $mainAudit
 * @param array<string, mixed> $boundary
 * @param array<string, mixed> $testMap
 * @param array<string, mixed> $staticAnalysis
 * @param array<string, mixed> $metadataFindings
 */
function buildMarkdownReport(
    string $root,
    int $targetLayer,
    string $layerName,
    string $outTag,
    array $mainAudit,
    array $boundary,
    array $testMap,
    array $staticAnalysis,
    array $metadataFindings
): string {
    $p = (array) ($mainAudit['priority_findings'] ?? []);
    $lines = [];
    $lines[] = "# Layer {$targetLayer} Forensic Audit ({$layerName})";
    $lines[] = '';
    $lines[] = 'Generated: ' . (string) ($mainAudit['generated_at'] ?? '') . ' UTC';
    $lines[] = '';
    $lines[] = '## 1. Canonical scope';
    $lines[] = '';
    $pk = (array) ($mainAudit['canonical_layer_packages'] ?? []);
    $lines[] = "Layer {$targetLayer} ({$layerName}) packages from `bin/check-package-layers` **LAYER_BY_SHORT**: " . implode(', ', $pk) . '.';
    $lines[] = '';
    $dr = (array) ($boundary['agents_md_drift'] ?? []);
    if (($dr['agents_parsed'] ?? false) === true) {
        $a = (array) ($dr['in_script_not_in_agents'] ?? []);
        $b = (array) ($dr['in_agents_not_in_script'] ?? []);
        if ($a === [] && $b === []) {
            $lines[] = '**Drift vs AGENTS.md:** none (package lists match the Layer Architecture table for this row).';
        } else {
            $lines[] = '**Drift vs AGENTS.md:** `in_script_not_in_agents` = ' . json_encode($a) . ', `in_agents_not_in_script` = ' . json_encode($b) . '.';
        }
    } else {
        $lines[] = '**Drift vs AGENTS.md:** could not parse Layer Architecture row for this layer.';
    }
    $lines[] = '';
    $lines[] = '## 2. Priority-ordered findings';
    $lines[] = '';
    usort(
        $p,
        static function (array $a, array $b): int {
            return ((int) ($a['priority'] ?? 99)) <=> ((int) ($b['priority'] ?? 99));
        }
    );
    foreach ($p as $item) {
        $lines[] = '### ' . (string) ($item['id'] ?? '');
        $lines[] = '- **Priority:** P' . (string) ($item['priority'] ?? '') . ' | **Category:** ' . (string) ($item['category'] ?? '') . ' | **Severity:** ' . (string) ($item['severity'] ?? '');
        $lines[] = '- **Message:** ' . (string) ($item['message'] ?? '');
        if (isset($item['detail'])) {
            $lines[] = '```json';
            $lines[] = json_encode($item['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '```';
        }
        $lines[] = '';
    }
    $lines[] = "## 3. PHPStan (L{$targetLayer} paths) and CI alignment";
    $lines[] = '';
    $l1i = (array) ($staticAnalysis['phpstan_neon']['packages_in_neon'] ?? []);
    $lines[] = '- `phpstan.neon` inclusions for this layer’s packages: ' . json_encode($l1i);
    $pj = $staticAnalysis['phpstan_json_parsed'] ?? null;
    if (\is_array($pj) && isset($pj['totals']['file_errors'], $pj['totals']['errors'])) {
        $lines[] = '- PHPStan totals (this run): ' . (string) $pj['totals']['errors'] . ' errors, ' . (string) $pj['totals']['file_errors'] . ' files with errors (see `layer' . $targetLayer . '_static_analysis.json`).';
    } else {
        $lines[] = '- See `layer' . $targetLayer . '_static_analysis.json` for raw output (if PHPStan output is not valid JSON, inspect `raw_stdout`).';
    }
    $lines[] = '';
    $lines[] = '## 4. Layer boundary (manifest + static `use`)';
    $lines[] = '';
    $lines[] = "Composer: `bin/check-package-layers` and `bin/audit-require-dev-layers` output is captured in `layer{$targetLayer}_layer_boundary_report.json`.";
    $lines[] = "Static: Waaseyaa `use` in L{$targetLayer} code targeting layer >{$targetLayer} — see P2-style findings. Group-`use` and other references are out of band for this pass.";
    $lines[] = '';
    $lines[] = '## 5. Metadata and extension points';
    $lines[] = '';
    $spc = \count((array) ($metadataFindings['service_providers'] ?? []));
    $lsc = \count((array) ($metadataFindings['subscribers'] ?? []));
    $ac = \count((array) ($metadataFindings['policy_or_gate_attributes'] ?? []));
    $lines[] = "Counts: service providers **{$spc}**, *Listener* classes **{$lsc}**, *Attribute* classes (heuristic) **{$ac}**. See `layer{$targetLayer}_metadata_consistency.json` for file paths.";
    $lines[] = '';
    $lines[] = '## 6. Test coverage (@covers + #[CoversClass])';
    $lines[] = '';
    $c = (int) ($testMap['at_covers_hits'] ?? 0);
    $lines[] = 'Unique FQCNs with at least one `@covers` or `#[CoversClass]`: ' . (string) $c;
    $lines[] = 'Public symbols with no indexed coverage: ' . (string) \count((array) ($testMap['public_non_internal_symbols_lacking_at_covers'] ?? [])) . " (see coverage finding and `symbol_test_map_layer{$targetLayer}.json`).";
    $lines[] = '';
    $lines[] = '## 7. Hygiene';
    $lines[] = '';
    $lines[] = "See `layer{$targetLayer}_hygiene_report.txt` for TODO/FIXME/HACK and risk patterns in this layer’s `src/`.";
    $lines[] = '';
    $lines[] = '## 8. Deliverable files';
    $lines[] = '';
    foreach ((array) ($mainAudit['deliverables'] ?? []) as $name) {
        $lines[] = "- `build/{$outTag}/" . (string) $name . '`';
    }

    return implode("\n", $lines) . "\n";
}
