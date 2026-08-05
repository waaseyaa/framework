<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

#[CoversClass(HttpKernel::class)]
final class ContentSearchStatelessPathTest extends TestCase
{
    #[Test]
    public function enabling_public_search_adds_its_exact_path_without_losing_operator_paths(): void
    {
        self::assertSame(
            [
                '/news',
                '/.well-known/api-catalog',
                '/api/content/search',
            ],
            $this->paths([
                'session' => ['stateless_paths' => ['/news']],
                'api' => ['content_search' => ['enabled' => true]],
            ]),
        );
    }

    #[Test]
    public function disabled_public_search_preserves_only_the_api_catalog_stateless_default(): void
    {
        $catalogPaths = ['/.well-known/api-catalog'];

        self::assertSame($catalogPaths, $this->paths([]));
        self::assertSame($catalogPaths, $this->paths(['api' => ['content_search' => ['enabled' => false]]]));
    }

    #[Test]
    public function ai_catalog_becomes_stateless_only_when_its_public_route_is_enabled(): void
    {
        self::assertSame(
            ['/.well-known/api-catalog', '/.well-known/ai-catalog.json'],
            $this->paths(['ai_catalog' => ['enabled' => true]]),
        );
        self::assertSame(
            ['/.well-known/api-catalog'],
            $this->paths(['ai_catalog' => ['enabled' => false]]),
        );
    }

    /** @param array<string, mixed> $config
     *  @return list<string>
     */
    private function paths(array $config): array
    {
        $kernel = (new \ReflectionClass(HttpKernel::class))->newInstanceWithoutConstructor();
        $configProperty = new \ReflectionProperty(AbstractKernel::class, 'config');
        $configProperty->setValue($kernel, $config);
        $method = new \ReflectionMethod(HttpKernel::class, 'sessionStatelessPaths');

        /** @var list<string> $paths */
        $paths = $method->invoke($kernel);

        return $paths;
    }
}
