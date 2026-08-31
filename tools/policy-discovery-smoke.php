<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

/**
 * Discovery parity for a fresh skeleton installed WITHOUT classmap
 * optimization: attribute scanning must still find the whole set.
 *
 * The expectation is deliberately not one flat set of totals. It is the
 * production floor plus a NAMED development-plane contribution, because the
 * skeleton installs `waaseyaa/ai-development` under `require-dev` (ADR-022
 * D-1.3) and the CI step installs with dev dependencies. When that plane's
 * surface changes, this must fail with the name of a tool, not with an
 * off-by-N that the next person resolves by editing a number.
 */
$projectRoot = $argv[1] ?? null;
if (!is_string($projectRoot) || !is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tools/policy-discovery-smoke.php <installed-project-root>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

/**
 * The production floor: what `waaseyaa/framework` alone contributes. These
 * totals are unchanged and must stay that way — a first-party package that
 * grows the production discovery set changes THESE numbers, and that is a
 * different review conversation from adding a development tool.
 */
const PRODUCTION_FLOOR = [
    'policies' => 18,
    'agent_tools' => 11,
    'formatters' => 6,
    'middleware' => 17,
    'schedule_entries' => 4,
];

/**
 * The development plane, named rather than counted.
 *
 * `waaseyaa/ai-development` requires `waaseyaa/ai-agent`, whose unconditional
 * `#[AsAgentTool]` surface is exactly these five Bimaaji tools.
 * `waaseyaa/testing`, the plane's other member, contributes no discovered
 * symbol of any kind.
 */
const DEVELOPMENT_PLANE_TOOLS = [
    'bimaaji_generate_patch',
    'bimaaji_introspect_graph',
    'bimaaji_introspect_section',
    'bimaaji_propose_mutation',
    'bimaaji_search_specs',
];

const DEVELOPMENT_PLANE_POLICIES = [
    'Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy',
];

/**
 * ai-agent's other five tools are gated by
 * `requiresPackage: 'waaseyaa/wayfinding'`, and ai-agent depends on
 * `waaseyaa/wayfinding` only through `require-dev`/`suggest`. So installing the
 * development plane must NOT pull the optional Wayfinding domain in with it.
 * Asserting their absence is the half a count could never express: without it,
 * the plane could quietly acquire an optional domain and the totals would still
 * be whatever we last wrote down.
 */
const WITHHELD_TOOL_PREFIX = 'wayfinding_';

$manifest = new PackageManifestCompiler(
    $projectRoot,
    $projectRoot . '/storage',
)->compile();

/** @var list<string> $toolNames */
$toolNames = array_column($manifest->agentTools, 'name');
$policyClasses = array_keys($manifest->policies);

$failures = [];

foreach (DEVELOPMENT_PLANE_TOOLS as $tool) {
    if (!in_array($tool, $toolNames, true)) {
        $failures[] = sprintf(
            'development-plane tool %s was not discovered; the skeleton installs waaseyaa/ai-development '
            . 'under require-dev, so it should be present',
            $tool,
        );
    }
}

foreach (DEVELOPMENT_PLANE_POLICIES as $policy) {
    if (!in_array($policy, $policyClasses, true)) {
        $failures[] = sprintf('development-plane policy %s was not discovered', $policy);
    }
}

foreach ($toolNames as $tool) {
    if (str_starts_with($tool, WITHHELD_TOOL_PREFIX)) {
        $failures[] = sprintf(
            'optional-domain tool %s was discovered; it declares requiresPackage waaseyaa/wayfinding, so the '
            . 'development plane has pulled an optional domain into the install',
            $tool,
        );
    }
}

$expected = PRODUCTION_FLOOR;
$expected['policies'] += count(DEVELOPMENT_PLANE_POLICIES);
$expected['agent_tools'] += count(DEVELOPMENT_PLANE_TOOLS);

$actual = [
    'policies' => count($manifest->policies),
    'agent_tools' => count($manifest->agentTools),
    'formatters' => count($manifest->formatters),
    'middleware' => array_sum(array_map('count', $manifest->middleware)),
    'schedule_entries' => count($manifest->scheduleEntries),
];

if ($actual !== $expected) {
    $failures[] = sprintf(
        'totals: expected %s (production floor %s plus the named development plane), got %s',
        json_encode($expected, JSON_THROW_ON_ERROR),
        json_encode(PRODUCTION_FLOOR, JSON_THROW_ON_ERROR),
        json_encode($actual, JSON_THROW_ON_ERROR),
    );
}

if ($failures !== []) {
    fwrite(STDERR, "DISCOVERY_PARITY_FAILURE:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

printf(
    "Discovery parity OK: %s (development plane: %d tool(s), %d policy(ies))\n",
    json_encode($actual, JSON_THROW_ON_ERROR),
    count(DEVELOPMENT_PLANE_TOOLS),
    count(DEVELOPMENT_PLANE_POLICIES),
);
