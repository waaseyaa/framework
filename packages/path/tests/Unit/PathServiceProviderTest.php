<?php

declare(strict_types=1);

namespace Waaseyaa\Path\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathServiceProvider;

#[CoversClass(PathServiceProvider::class)]
final class PathServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_path_alias(): void
    {
        $provider = new PathServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(1, $entityTypes);
        $this->assertSame('path_alias', $entityTypes[0]->id());
        $this->assertSame(PathAlias::class, $entityTypes[0]->getClass());
    }
}
