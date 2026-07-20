<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

$projectRoot = $argv[1] ?? null;
if (!is_string($projectRoot) || !is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/policy-discovery-smoke.php <installed-project-root>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

$manifest = (new PackageManifestCompiler(
    $projectRoot,
    $projectRoot . '/storage',
))->compile();

$actual = [
    'policies' => count($manifest->policies),
    'agent_tools' => count($manifest->agentTools),
    'formatters' => count($manifest->formatters),
    'middleware' => array_sum(array_map('count', $manifest->middleware)),
    'schedule_entries' => count($manifest->scheduleEntries),
];
$expected = [
    'policies' => 21,
    'agent_tools' => 21,
    'formatters' => 6,
    'middleware' => 16,
    'schedule_entries' => 4,
];

if ($actual !== $expected) {
    fwrite(STDERR, sprintf(
        "DISCOVERY_PARITY_FAILURE: expected %s, got %s\n",
        json_encode($expected, JSON_THROW_ON_ERROR),
        json_encode($actual, JSON_THROW_ON_ERROR),
    ));
    exit(1);
}

printf("Discovery parity OK: %s\n", json_encode($actual, JSON_THROW_ON_ERROR));
