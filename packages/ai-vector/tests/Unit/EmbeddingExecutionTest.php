<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\AI\Vector\EmbeddingHttpTransport;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\OllamaEmbeddingProvider;
use Waaseyaa\AI\Vector\OpenAiEmbeddingProvider;
use Waaseyaa\Entity\Event\EntityEvent;

#[CoversClass(OllamaEmbeddingProvider::class)]
#[CoversClass(OpenAiEmbeddingProvider::class)]
#[CoversClass(EmbeddingHttpTransport::class)]
#[CoversClass(EntityEmbeddingListener::class)]
final class EmbeddingExecutionTest extends TestCase
{
    public static function nativeCases(): iterable
    {
        yield 'success' => ['success'];
        yield 'total-trickle-deadline' => ['trickle'];
        yield 'oversized' => ['oversized'];
    }

    #[DataProvider('nativeCases')]
    public function test_native_symfony_transport_without_curl_selection(string $mode): void
    {
        $peer = new Process([PHP_BINARY, __DIR__ . '/../Support/embedding-http-peer.php', $mode, '{"embedding":[1,2]}'], timeout: 10);
        try {
            $peer->start();
            $deadline = hrtime(true) + 3_000_000_000;
            do {
                if (preg_match('/^READY (\d+)\n/', $peer->getOutput(), $match) === 1) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline && $peer->isRunning());
            self::assertNotEmpty($match[1] ?? null, $peer->getErrorOutput());
            $client = new \Waaseyaa\HttpClient\SymfonyHttpClient(2, 1048576, new \Symfony\Component\HttpClient\NativeHttpClient());
            $start = hrtime(true);
            try {
                $response = $client->post('http://127.0.0.1:' . $match[1] . '/embed', [], ['prompt' => 'configured text']);
                self::assertSame('success', $mode);
                self::assertSame(200, $response->statusCode);
                self::assertSame(['embedding' => [1, 2]], $response->json());
            } catch (\Waaseyaa\HttpClient\HttpRequestException $error) {
                self::assertNotSame('success', $mode);
                self::assertNull($error->getPrevious());
            }
            self::assertLessThan(3.0, (hrtime(true) - $start) / 1e9);
        } finally {
            if ($peer->isRunning()) {
                $peer->stop(0.2);
            }
        }
    }

    public static function cases(): iterable
    {
        foreach (['ollama', 'openai'] as $provider) {
            foreach (['success', 'error', 'redirect', 'invalid', 'scalar', 'shape', 'oversized', 'silent', 'body-stall', 'trickle', 'slow-success'] as $mode) {
                yield "$provider-$mode" => [$provider, $mode];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_real_http_execution(string $kind, string $mode): void
    {
        $response = $kind === 'ollama' ? '{"embedding":[1,2]}' : '{"data":[{"embedding":[1,2]}]}';
        $peer = new Process([PHP_BINARY, __DIR__ . '/../Support/embedding-http-peer.php', $mode, $response], timeout: 10);
        try {
            $peer->start();
            $deadline = hrtime(true) + 3_000_000_000;
            do {
                $output = $peer->getOutput();
                if (preg_match('/^READY (\d+)\n/', $output, $match) === 1) {
                    break;
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline && $peer->isRunning());
            self::assertNotEmpty($match[1] ?? null, $peer->getErrorOutput());
            $endpoint = 'http://127.0.0.1:' . $match[1] . '/embed';
            $provider = $kind === 'ollama'
                ? new OllamaEmbeddingProvider(endpoint: $endpoint)
                : new OpenAiEmbeddingProvider(apiKey: 'synthetic-key', endpoint: $endpoint);
            $started = hrtime(true);
            try {
                if (in_array($mode, ['silent', 'body-stall', 'trickle'], true)) {
                    $storage = $this->createMock(EmbeddingStorageInterface::class);
                    $storage->expects(self::never())->method('store');
                    $storage->expects(self::once())->method('delete')->with('node', '42');
                    $policy = EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => [
                        'node' => ['fields' => ['title'], 'allow_external' => true],
                    ]]]);
                    $served = $this->createStub(\Waaseyaa\Entity\EntityInterface::class);
                    $served->method('id')->willReturn('42');
                    $served->method('getEntityTypeId')->willReturn('node');
                    $served->method('get')->willReturnCallback(static fn(string $field): mixed => ['title' => 'configured text', 'status' => 1, 'workflow_state' => 'published'][$field] ?? null);
                    $repository = $this->createStub(\Waaseyaa\Entity\Repository\EntityRepositoryInterface::class);
                    $repository->method('find')->willReturn($served);
                    $manager = $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class);
                    $manager->method('getRepository')->willReturn($repository);
                    $listener = new EntityEmbeddingListener(storage: $storage, embeddingProvider: $provider, indexPolicy: $policy, entityTypeManager: $manager, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
                    $listener->onPostSave(new EntityEvent($served));
                    $vector = null;
                } else {
                    $vector = $provider->embed('configured text');
                }
                if ($vector !== null) {
                    self::assertContains($mode, ['success', 'slow-success'], 'An invalid response must refuse.');
                    self::assertSame([1.0, 2.0], $vector);
                }
            } catch (\RuntimeException $error) {
                self::assertNotContains($mode, ['success', 'slow-success'], $error->getMessage());
                self::assertStringNotContainsString('synthetic-key', $error->getMessage());
                self::assertStringNotContainsString($endpoint, $error->getMessage());
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            self::assertLessThan(3.0, $elapsed, 'Two-second network deadline plus one-second scheduler tolerance.');
            if (in_array($mode, ['silent', 'body-stall', 'trickle', 'slow-success'], true)) {
                self::assertGreaterThan($mode === 'slow-success' ? 2.0 : 1.5, $elapsed, 'The peer must exercise the applicable budget.');
            }
            $lines = explode("\n", trim($peer->getOutput()));
            $request = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('POST /embed HTTP/', $request['headers']);
            $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('configured text', $payload[$kind === 'ollama' ? 'prompt' : 'input']);
            if ($kind === 'openai') {
                self::assertStringContainsString('Authorization: Bearer synthetic-key', $request['headers']);
            }
        } finally {
            if ($peer->isRunning()) {
                $peer->stop(0.2);
            }
        }
    }
}
