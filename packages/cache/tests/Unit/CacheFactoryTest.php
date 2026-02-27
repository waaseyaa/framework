<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit;

use Aurora\Cache\Backend\MemoryBackend;
use Aurora\Cache\Backend\NullBackend;
use Aurora\Cache\CacheFactory;
use Aurora\Cache\CacheFactoryInterface;
use PHPUnit\Framework\TestCase;

final class CacheFactoryTest extends TestCase
{
    public function testGetReturnsSameBinInstance(): void
    {
        $factory = new CacheFactory();

        $bin1 = $factory->get('default');
        $bin2 = $factory->get('default');

        $this->assertSame($bin1, $bin2);
    }

    public function testGetDifferentBinsReturnsDifferentInstances(): void
    {
        $factory = new CacheFactory();

        $default = $factory->get('default');
        $render = $factory->get('render');

        $this->assertNotSame($default, $render);
    }

    public function testDefaultBackendIsMemoryBackend(): void
    {
        $factory = new CacheFactory();

        $bin = $factory->get('test');

        $this->assertInstanceOf(MemoryBackend::class, $bin);
    }

    public function testCustomBackendClass(): void
    {
        $factory = new CacheFactory(NullBackend::class);

        $bin = $factory->get('test');

        $this->assertInstanceOf(NullBackend::class, $bin);
    }

    public function testImplementsCacheFactoryInterface(): void
    {
        $factory = new CacheFactory();

        $this->assertInstanceOf(CacheFactoryInterface::class, $factory);
    }
}
