<?php

declare(strict_types=1);

// Isolated test tooling stays outside the installed no-dev process.
require $argv[3] . '/vendor/autoload.php';
use Symfony\Component\Process\Process;

$profiles = ['default-provider', 'native'];
if (extension_loaded('curl')) {
    $profiles[] = 'curl';
}
foreach ($profiles as $profile) {
    $peer = new Process([PHP_BINARY, $argv[2], 'success', '{"embedding":[1,0]}'], timeout: 8);
    try {
        $peer->start();
        $ready = $peer->waitUntil(static fn(string $type, string $output): bool => str_contains($output, 'READY '));
        if (!$ready || preg_match('/READY (\d+)/', $peer->getOutput(), $match) !== 1) {
            throw new RuntimeException('Installed transport peer did not become ready.');
        }
        $endpoint = 'http://127.0.0.1:' . $match[1] . '/embed';
        $consumer = new Process([PHP_BINARY, __DIR__ . '/ai-vector-installed-client.php', $argv[1], $endpoint, $profile], timeout: 8);
        $consumer->mustRun();
        if ($consumer->getOutput() !== 'installed ai-vector transport OK (' . $profile . ")\n" || $consumer->getErrorOutput() !== '') {
            throw new RuntimeException('Installed consumer transport proof failed.');
        }
        $peer->wait();
        if ($peer->getExitCode() !== 0 || $peer->getErrorOutput() !== '') {
            throw new RuntimeException('Installed transport peer failed.');
        }
        echo 'installed ai-vector transport OK (' . $profile . ")\n";
    } finally {
        if ($peer->isRunning()) {
            $peer->stop(0);
        }
    }
}
