<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provenance;

/**
 * Inspects composer.json / composer.lock for waaseyaa/* provenance (path SHA, versions, drift).
 * @api
 */
final class ComposerProvenanceReporter
{
    private const GOLDEN_FILE = '.waaseyaa-golden-sha';

    /**
     * Standalone entrypoint for bin/waaseyaa-version (no Symfony kernel).
     *
     * Recognized flags: `--json`, `--report-only`, `--strict` (ignored; same exit as default).
     *
     * @param list<string> $argv
     */
    public static function main(string $projectRoot, array $argv): int
    {
        $json = in_array('--json', $argv, true);
        $reportOnly = in_array('--report-only', $argv, true);

        $reporter = new self($projectRoot);
        $report = $reporter->analyze();

        if ($json) {
            fwrite(STDOUT, json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
        } else {
            self::printHuman($report);
        }

        if ($reportOnly) {
            return 0;
        }

        return $report->hasDrift() ? 1 : 0;
    }

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function analyze(): ProvenanceReport
    {
        $golden = $this->resolveGoldenSha();

        $composerPath = $this->projectRoot . '/composer.json';
        $lockPath = $this->projectRoot . '/composer.lock';

        $constraints = [];
        if (is_readable($composerPath)) {
            $composer = $this->decodeJson($composerPath);
            $constraints = $this->extractWaaseyaaConstraints($composer);
        }

        $packages = [];
        $pathHeads = [];
        $hasPath = false;
        $hasDist = false;

        if (is_readable($lockPath)) {
            $lock = $this->decodeJson($lockPath);
            $merged = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
            foreach ($merged as $pkg) {
                if (!is_array($pkg) || !isset($pkg['name']) || !is_string($pkg['name'])) {
                    continue;
                }
                if (!str_starts_with($pkg['name'], 'waaseyaa/')) {
                    continue;
                }
                $name = $pkg['name'];
                $version = isset($pkg['version']) && is_string($pkg['version']) ? $pkg['version'] : '';
                $dist = $pkg['dist'] ?? null;
                $distType = is_array($dist) && isset($dist['type']) && is_string($dist['type']) ? $dist['type'] : '';
                $distUrl = is_array($dist) && isset($dist['url']) && is_string($dist['url']) ? $dist['url'] : '';
                $distRef = is_array($dist) && isset($dist['reference']) && is_string($dist['reference']) ? $dist['reference'] : null;

                $sourceKind = 'unknown';
                $resolvedPath = null;
                $gitHead = null;

                if ($distType === 'path' && $distUrl !== '') {
                    $hasPath = true;
                    $sourceKind = 'path';
                    $resolvedPath = $this->resolvePath($distUrl);
                    if ($resolvedPath !== null) {
                        $gitHead = $this->gitRevParseHead($resolvedPath);
                        if ($gitHead !== null) {
                            $pathHeads[$gitHead] = ($pathHeads[$gitHead] ?? 0) + 1;
                        }
                    }
                } elseif ($distType === 'zip' || $distType === 'tar') {
                    $hasDist = true;
                    $sourceKind = 'packagist';
                } elseif ($distType !== '') {
                    $hasDist = true;
                    $sourceKind = $distType;
                }

                $packages[] = new InstalledWaaseyaaPackage(
                    name: $name,
                    lockedVersion: $version,
                    sourceKind: $sourceKind,
                    distUrl: $distUrl !== '' ? $distUrl : null,
                    distReference: $distRef,
                    resolvedPath: $resolvedPath,
                    gitHead: $gitHead,
                );
            }
        }

        $uniqueConstraints = array_values(array_unique(array_values($constraints)));
        $constraintDrift = count($uniqueConstraints) > 1;

        $pathHeadList = array_keys($pathHeads);
        $multiplePathHeads = $hasPath && count($pathHeadList) > 1;

        $mixedPathAndPackagist = $hasPath && $hasDist;

        $goldenMismatch = false;
        $goldenMessage = null;
        if ($golden !== null && $golden !== '') {
            if ($hasPath && $pathHeadList !== []) {
                foreach ($pathHeadList as $head) {
                    if (! self::headMatchesGolden($head, $golden)) {
                        $goldenMismatch = true;
                        $goldenMessage = 'path checkout HEAD does not match WAASEYAA_GOLDEN_SHA / .waaseyaa-golden-sha';
                        break;
                    }
                }
            } elseif ($hasDist && !$hasPath) {
                $goldenMessage = 'golden SHA set but only Packagist/dist installs; cannot verify monorepo SHA from lockfile';
                $goldenMismatch = true;
            }
        }

        $driftMessages = [];
        if ($constraintDrift) {
            $driftMessages[] = 'multiple distinct waaseyaa/* constraint lines in composer.json';
        }
        if ($mixedPathAndPackagist) {
            $driftMessages[] = 'mixed path and dist installs for waaseyaa/* packages';
        }
        if ($multiplePathHeads) {
            $driftMessages[] = 'multiple distinct Git HEAD values under path installs (expected one monorepo checkout)';
        }
        if ($goldenMismatch && $goldenMessage !== null) {
            $driftMessages[] = $goldenMessage;
        }

        $primaryPathHead = count($pathHeadList) === 1 ? $pathHeadList[0] : null;

        if ($hasPath && $pathHeadList === []) {
            $driftMessages[] = 'path installs present but Git HEAD could not be resolved (git missing or not a checkout)';
        }

        $rootDisplay = realpath($this->projectRoot) ?: $this->projectRoot;

        return new ProvenanceReport(
            goldenSha: $golden,
            constraints: $constraints,
            uniqueConstraints: $uniqueConstraints,
            packages: $packages,
            pathMonorepoHead: $primaryPathHead,
            driftMessages: $driftMessages,
            projectRoot: $rootDisplay,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $composer
     *
     * @return array<string, string> package name => constraint
     */
    private function extractWaaseyaaConstraints(array $composer): array
    {
        $out = [];
        foreach (['require', 'require-dev'] as $section) {
            $req = $composer[$section] ?? null;
            if (!is_array($req)) {
                continue;
            }
            foreach ($req as $pkg => $ver) {
                if (!is_string($pkg) || !is_string($ver)) {
                    continue;
                }
                if (!str_starts_with($pkg, 'waaseyaa/')) {
                    continue;
                }
                $out[$pkg] = $ver;
            }
        }

        return $out;
    }

    private function resolveGoldenSha(): ?string
    {
        $env = getenv('WAASEYAA_GOLDEN_SHA');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        $file = $this->projectRoot . '/' . self::GOLDEN_FILE;
        if (!is_readable($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $parts = preg_split('/\R/', $raw, 2);
        $trim = trim($parts[0] ?? '');

        return $trim !== '' ? $trim : null;
    }

    private function resolvePath(string $relativeOrAbsolute): ?string
    {
        if ($relativeOrAbsolute === '') {
            return null;
        }
        $base = $this->projectRoot;
        if (str_starts_with($relativeOrAbsolute, '/')) {
            $candidate = $relativeOrAbsolute;
        } else {
            $candidate = $base . '/' . $relativeOrAbsolute;
        }
        $real = realpath($candidate);

        return $real !== false ? $real : null;
    }

    private function gitRevParseHead(string $inPath): ?string
    {
        if (!is_dir($inPath)) {
            return null;
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = ['git', '-C', $inPath, 'rev-parse', 'HEAD'];
        $process = proc_open($cmd, $descriptorSpec, $pipes, null, null);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_string($stdout)) {
            return null;
        }
        $sha = trim($stdout);

        return preg_match('/^[a-f0-9]{40}$/i', $sha) === 1 ? strtolower($sha) : null;
    }

    /**
     * Print a human-readable provenance report.
     *
     * @param null|\Closure(string): void $writeLine
     */
    public static function printHuman(ProvenanceReport $report, ?\Closure $writeLine = null): void
    {
        $out = $writeLine ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };

        $out('Waaseyaa framework provenance');
        $out('Project root: ' . $report->projectRootDisplay());
        $out('');

        if ($report->goldenSha !== null) {
            $out('Golden SHA: ' . $report->goldenSha);
        } else {
            $out('Golden SHA: (not set — WAASEYAA_GOLDEN_SHA or .waaseyaa-golden-sha)');
        }

        if ($report->pathMonorepoHead !== null) {
            $out('Path monorepo HEAD: ' . $report->pathMonorepoHead);
        } elseif ($report->hasPathInstalls()) {
            $out('Path monorepo HEAD: (unresolved — run from project with path deps and git available)');
        } else {
            $out('Path monorepo HEAD: (no path installs in lockfile)');
        }

        $out('');
        $out('composer.json waaseyaa/* constraint patterns: ' . count($report->uniqueConstraints));
        foreach ($report->uniqueConstraints as $c) {
            $out('  - ' . $c);
        }

        $out('');
        $out('Resolved waaseyaa/* (composer.lock):');
        foreach ($report->packages as $p) {
            $line = sprintf(
                '  %-28s %-18s %s',
                $p->name,
                $p->lockedVersion,
                $p->sourceKind,
            );
            if ($p->gitHead !== null) {
                $line .= ' head=' . $p->gitHead;
            }
            $out($line);
        }

        $out('');
        $out('Drift summary:');
        if ($report->driftMessages === []) {
            $out('  (none)');
        } else {
            foreach ($report->driftMessages as $m) {
                $out('  - ' . $m);
            }
        }
    }

    private static function headMatchesGolden(string $head, string $golden): bool
    {
        $h = strtolower($head);
        $g = strtolower(trim($golden));
        if ($g === '') {
            return true;
        }
        if (strlen($g) >= 40) {
            return $h === $g;
        }

        return str_starts_with($h, $g);
    }
}
