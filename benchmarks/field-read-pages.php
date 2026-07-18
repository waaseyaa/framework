#!/usr/bin/env php
<?php

declare(strict_types=1);

use Waaseyaa\Tests\Integration\FieldReadPagePerformance\PagePerformanceOrchestrator;

$harnessRoot = dirname(__DIR__);
$contractRoot = $harnessRoot.'/tests/Integration/FieldReadPagePerformance';
$fixtureRoot = $contractRoot.'/Fixtures';
require $contractRoot.'/PagePerformanceOrchestrator.php';

$options = getopt('', ['baseline-root:', 'candidate-root:', 'output::', 'validate-only']);
if (!is_string($options['baseline-root'] ?? null) || !is_string($options['candidate-root'] ?? null)) {
    fwrite(STDERR, "Usage: benchmarks/field-read-pages.php --baseline-root=/exact/wp2/tree --candidate-root=/wp4/tree [--output=report.json] [--validate-only]\n");
    exit(2);
}

try {
    $baselineRoot = (string) realpath((string) $options['baseline-root']);
    $candidateRoot = (string) realpath((string) $options['candidate-root']);
    PagePerformanceOrchestrator::validateSourceTrees($baselineRoot, $candidateRoot);
    $frozenManifest = assertFrozenHarness($harnessRoot, $contractRoot.'/fixture-manifest.json');
    $identity = [
        'baseline' => sourceIdentity($baselineRoot),
        'candidate' => sourceIdentity($candidateRoot),
        'fixture_manifest_sha256' => hash('sha256', stableJson($frozenManifest)),
        'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
    ];
    if (array_key_exists('validate-only', $options)) {
        emitReport(['passed' => true, 'validate_only' => true, 'identity' => $identity], $options['output'] ?? null);
        exit(0);
    }

    $workingRoot = makeTemporaryDirectory('waaseyaa-field-read-pages');
    $baseProject = $workingRoot.'/frozen-base';
    runWorker('prepare', $baselineRoot, $fixtureRoot, $baseProject, 0);
    $baseDatabaseHash = hash_file('sha256', $baseProject.'/storage/waaseyaa.sqlite');

    $blocks = ['baseline' => [], 'candidate' => []];
    $blockOrders = [];
    $orderSeed = 20_641;
    $orderRandomizer = new Random\Randomizer(new Random\Engine\Mt19937($orderSeed));
    for ($block = 0; $block < PagePerformanceOrchestrator::BLOCKS; ++$block) {
        $projects = [];
        foreach (['baseline' => $baselineRoot, 'candidate' => $candidateRoot] as $role => $sourceRoot) {
            $project = sprintf('%s/block-%02d-%s', $workingRoot, $block + 1, $role);
            copyDirectory($baseProject, $project);
            $retarget = runWorker('retarget', $sourceRoot, $fixtureRoot, $project, $block + 1);
            if (($retarget['database_sha256'] ?? null) !== $baseDatabaseHash) {
                throw new RuntimeException(sprintf('%s block %d changed the frozen database while retargeting.', $role, $block + 1));
            }
            $projects[$role] = $project;
        }

        $order = $orderRandomizer->shuffleArray(['baseline', 'candidate']);
        $blockOrders[] = $order;
        foreach ($order as $role) {
            $sourceRoot = $role === 'baseline' ? $baselineRoot : $candidateRoot;
            $result = runWorker('measure', $sourceRoot, $fixtureRoot, $projects[$role], $block + 1);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException(sprintf('%s block %d failed.', $role, $block + 1));
            }
            $blocks[$role][] = $result;
        }
    }

    $comparisons = [];
    foreach (['content_cold', 'members_cold', 'content_hit_diagnostic'] as $page) {
        $baselinePages = pageBlocks($blocks['baseline'], $page);
        $candidatePages = pageBlocks($blocks['candidate'], $page);
        $comparisons[$page] = PagePerformanceOrchestrator::comparePage($baselinePages, $candidatePages);
        $comparisons[$page]['diagnostic_only'] = $page === 'content_hit_diagnostic';
    }
    $passed = PagePerformanceOrchestrator::finalVerdict($comparisons);
    $report = [
        'passed' => $passed,
        'identity' => $identity,
        'constants' => [
            'blocks' => PagePerformanceOrchestrator::BLOCKS,
            'warmups' => PagePerformanceOrchestrator::WARMUPS,
            'samples' => PagePerformanceOrchestrator::SAMPLES,
            'ratio_limit' => PagePerformanceOrchestrator::RATIO_LIMIT,
            'absolute_limit_ns' => PagePerformanceOrchestrator::ABSOLUTE_LIMIT_NS,
        ],
        'base_database_sha256' => $baseDatabaseHash,
        'process_order_seed' => $orderSeed,
        'block_orders' => $blockOrders,
        'pages' => $comparisons,
        'raw_blocks' => $blocks,
    ];
    emitReport($report, $options['output'] ?? null);
    if ($passed) {
        removeDirectory($workingRoot);
    } else {
        fwrite(STDERR, sprintf("Page gate failed; retained exact projects at %s\n", $workingRoot));
    }
    exit($passed ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(2);
}

/** @return array<string,string> */
function assertFrozenHarness(string $root, string $manifestPath): array
{
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Frozen fixture manifest is missing.');
    }
    $expected = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($expected)) {
        throw new RuntimeException('Frozen fixture manifest is invalid.');
    }
    $actual = [];
    foreach ($expected as $relative => $hash) {
        if (!is_string($relative) || !is_string($hash) || !is_file($root.'/'.$relative)) {
            throw new RuntimeException(sprintf('Frozen fixture manifest entry is invalid: %s', (string) $relative));
        }
        $actual[$relative] = hash_file('sha256', $root.'/'.$relative);
    }
    PagePerformanceOrchestrator::assertSameFixtureManifest($expected, $actual);

    return $actual;
}

/** @return array<string,string> */
function sourceIdentity(string $root): array
{
    $head = trim(runCommand([$root.'/bin/git', 'rev-parse', 'HEAD'], $root)['stdout']);
    $status = runCommand([$root.'/bin/git', 'status', '--short', '--untracked-files=all'], $root);
    if ($status['exit_code'] !== 0) {
        throw new RuntimeException(sprintf('Could not verify immutable source tree: %s', $root));
    }
    if (trim($status['stdout']) !== '') {
        throw new RuntimeException(sprintf('Source tree must be clean and immutable before measurement: %s', $root));
    }
    return [
        'root' => $root,
        'head' => $head,
        'framework_sha256' => frameworkHash($root),
        'composer_lock_sha256' => is_file($root.'/composer.lock') ? hash_file('sha256', $root.'/composer.lock') : 'missing',
        'vendor_installed_sha256' => is_file($root.'/vendor/composer/installed.json') ? hash_file('sha256', $root.'/vendor/composer/installed.json') : 'missing',
    ];
}

function frameworkHash(string $root): string
{
    $files = [];
    foreach (['packages', 'src'] as $directory) {
        if (!is_dir($root.'/'.$directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    foreach (['composer.json', 'composer.lock'] as $relative) {
        if (is_file($root.'/'.$relative)) {
            $files[$relative] = hash_file('sha256', $root.'/'.$relative);
        }
    }
    ksort($files);

    return hash('sha256', stableJson($files));
}

/** @return array<string,mixed> */
function runWorker(string $mode, string $sourceRoot, string $fixtureRoot, string $projectRoot, int $block): array
{
    $command = [
        PHP_BINARY,
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.jit=0',
        '-d', 'xdebug.mode=off',
        '-d', 'zend.assertions=-1',
        '-d', 'memory_limit=1G',
        $fixtureRoot.'/persistent_http_runner.php',
        $mode,
        $sourceRoot,
        $fixtureRoot,
        $projectRoot,
        (string) $block,
    ];
    $result = runCommand($command, $sourceRoot);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException(sprintf("Worker %s failed for %s:\n%s\n%s", $mode, $sourceRoot, $result['stderr'], $result['stdout']));
    }
    $lines = array_values(array_filter(preg_split('/\R/', trim($result['stdout'])) ?: [], static fn(string $line): bool => $line !== ''));
    $payload = json_decode($lines[array_key_last($lines)] ?? '', true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
        throw new RuntimeException(sprintf('Worker %s returned an invalid result.', $mode));
    }

    return $payload;
}

/** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
function pageBlocks(array $blocks, string $page): array
{
    $pages = [];
    foreach ($blocks as $block) {
        $result = $block['pages'][$page] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException(sprintf('Worker result omitted page %s.', $page));
        }
        $result['environment'] = $block['environment'] ?? [];
        $pages[] = $result;
    }
    return $pages;
}

/** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
function runCommand(array $command, string $cwd): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start benchmark subprocess.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function makeTemporaryDirectory(string $prefix): string
{
    $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    if (!mkdir($path, 0o755, true)) {
        throw new RuntimeException(sprintf('Could not create temporary directory: %s', $path));
    }
    return $path;
}

function copyDirectory(string $source, string $target): void
{
    if (!mkdir($target, 0o755, true) && !is_dir($target)) {
        throw new RuntimeException(sprintf('Could not create clone directory: %s', $target));
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $destination = $target.'/'.substr($item->getPathname(), strlen($source) + 1);
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0o755, true)) {
                throw new RuntimeException(sprintf('Could not create clone directory: %s', $destination));
            }
        } elseif (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException(sprintf('Could not copy fixture file: %s', $destination));
        }
    }
}

function removeDirectory(string $path): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

/** @param array<string,mixed> $report */
function emitReport(array $report, mixed $output): void
{
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (is_string($output) && $output !== '') {
        file_put_contents($output, $json, LOCK_EX);
        return;
    }
    echo $json;
}

/** @param array<string,mixed> $value */
function stableJson(array $value): string
{
    ksort($value);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
