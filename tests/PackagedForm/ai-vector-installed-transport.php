<?php

declare(strict_types=1);

// External acceptance harness. All production classes come from the installed
// no-dev consumer; the peer supplies only synthetic loopback HTTP responses.
require $argv[1] . '/vendor/autoload.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\Process\Process;
use Waaseyaa\AI\Vector\OllamaEmbeddingProvider;
use Waaseyaa\HttpClient\SymfonyHttpClient;

foreach ([OllamaEmbeddingProvider::class, SymfonyHttpClient::class] as $class) {
    $origin = realpath(new ReflectionClass($class)->getFileName());
    if (!is_string($origin) || !str_starts_with($origin, realpath($argv[1] . '/vendor') . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Production transport resolved outside the installed consumer.');
    }
}

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
        if ($profile === 'default-provider') {
            $vector = new OllamaEmbeddingProvider(endpoint: $endpoint, dimensions: 2)->embedForSave('synthetic installed content');
            if ($vector !== [1.0, 0.0]) {
                throw new RuntimeException('Installed provider returned an invalid vector.');
            }
        } else {
            $client = $profile === 'native' ? new NativeHttpClient() : new CurlHttpClient();
            $response = new SymfonyHttpClient(2, 1048576, $client)->post($endpoint, [], ['prompt' => 'synthetic installed content']);
            if ($response->statusCode !== 200 || $response->body !== '{"embedding":[1,0]}') {
                throw new RuntimeException('Installed Symfony transport response mismatch.');
            }
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
