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
            ['/news', '/api/content/search'],
            $this->paths([
                'session' => ['stateless_paths' => ['/news']],
                'api' => ['content_search' => ['enabled' => true]],
            ]),
        );
    }

    #[Test]
    public function disabled_public_search_does_not_change_session_defaults(): void
    {
        self::assertSame([], $this->paths([]));
        self::assertSame([], $this->paths(['api' => ['content_search' => ['enabled' => false]]]));
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
