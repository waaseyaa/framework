<?php

declare(strict_types=1);

// This process has only the installed consumer autoloader, never root dev code.
require $argv[1] . '/vendor/autoload.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Waaseyaa\AI\Vector\OllamaEmbeddingProvider;
use Waaseyaa\HttpClient\SymfonyHttpClient;

foreach ([OllamaEmbeddingProvider::class, SymfonyHttpClient::class] as $class) {
    $origin = realpath(new ReflectionClass($class)->getFileName());
    if (!is_string($origin) || !str_starts_with($origin, realpath($argv[1] . '/vendor') . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Production transport resolved outside the installed consumer.');
    }
}
$profile = $argv[3];
if ($profile === 'default-provider') {
    $vector = new OllamaEmbeddingProvider(endpoint: $argv[2], dimensions: 2)->embedForSave('synthetic installed content');
    if ($vector !== [1.0, 0.0]) {
        throw new RuntimeException('Installed provider returned an invalid vector.');
    }
} else {
    $client = $profile === 'native' ? new NativeHttpClient() : new CurlHttpClient();
    $response = new SymfonyHttpClient(2, 1048576, $client)->post($argv[2], [], ['prompt' => 'synthetic installed content']);
    if ($response->statusCode !== 200 || $response->body !== '{"embedding":[1,0]}') {
        throw new RuntimeException('Installed Symfony transport response mismatch.');
    }
}
echo 'installed ai-vector transport OK (' . $profile . ")\n";
