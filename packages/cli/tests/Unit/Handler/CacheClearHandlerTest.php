<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheFactoryInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(CacheClearHandler::class)]
final class CacheClearHandlerTest extends TestCase
{
    #[Test]
    public function clearsAllDefaultBins(): void
    {
        $mockBackend = $this->createMock(CacheBackendInterface::class);
        $mockBackend->expects($this->exactly(4))->method('deleteAll');

        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('All cache bins cleared.', $tester->getStdout());
    }

    #[Test]
    public function clearsSpecificBin(): void
    {
        $mockBackend = $this->createMock(CacheBackendInterface::class);
        $mockBackend->expects($this->once())->method('deleteAll');

        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->expects($this->once())
            ->method('get')
            ->with('render')
            ->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory);
        $tester->executeMap(['--bin' => 'render']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "render" cleared.', $tester->getStdout());
    }

    #[Test]
    public function invalidatesByTagsForTagAwareBins(): void
    {
        $mockBackend = $this->createMock(TagAwareCacheInterface::class);
        $mockBackend->expects($this->exactly(4))
            ->method('invalidateByTags')
            ->with(['render']);

        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory);
        $tester->executeMap(['--tags' => 'render']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('invalidated by tags: render', $tester->getStdout());
    }

    private function createTester(CacheFactoryInterface $factory): CliTester
    {
        $handler = new CacheClearHandler($factory);
        $definition = new HandlerCommand(
            name: 'cache:clear',
            description: 'Clear one or all cache bins',
            options: [
                new HandlerOption(name: 'bin', shortcut: 'b', mode: HandlerOptionMode::Required, description: 'Clear a specific cache bin instead of all bins'),
                new HandlerOption(name: 'tags', mode: HandlerOptionMode::Required, description: 'Invalidate cache entries by comma-separated tags'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
