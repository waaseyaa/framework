<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit;

use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\Backend\NullBackend;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(CacheFactory::class)]
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

    // ---------------------------------------------------------------------------
    // Tests covering factory-callable support (DatabaseBackend use-case)
    // ---------------------------------------------------------------------------

    #[Test]
    public function database_backend_via_bin_factory_callable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $config = new CacheConfiguration();
        $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo, 'cache_db'));

        $factory = new CacheFactory($config);
        $bin = $factory->get('cache_db');

        $this->assertInstanceOf(DatabaseBackend::class, $bin);
    }

    #[Test]
    public function factory_callable_backend_is_cached_for_same_bin(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $config = new CacheConfiguration();
        $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo, 'cache_db'));

        $factory = new CacheFactory($config);
        $first = $factory->get('cache_db');
        $second = $factory->get('cache_db');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function factory_callable_backend_is_functional(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        RuntimeSchemaMigrations::cachePdo($pdo);
        $config = new CacheConfiguration();
        $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo, 'cache_db'));

        $factory = new CacheFactory($config);
        $bin = $factory->get('cache_db');

        $bin->set('key', 'value');
        $item = $bin->get('key');

        $this->assertNotFalse($item);
        $this->assertSame('value', $item->data);
    }

    #[Test]
    public function default_factory_callable_used_for_unmapped_bins(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $config = new CacheConfiguration(
            defaultFactory: fn() => new DatabaseBackend($pdo),
        );

        $factory = new CacheFactory($config);
        $bin = $factory->get('any_bin');

        $this->assertInstanceOf(DatabaseBackend::class, $bin);
    }

    #[Test]
    public function bin_factory_takes_precedence_over_default_class(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        // Default is MemoryBackend but 'cache_db' bin uses a factory.
        $config = new CacheConfiguration(MemoryBackend::class);
        $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo, 'cache_db'));

        $factory = new CacheFactory($config);

        $this->assertInstanceOf(DatabaseBackend::class, $factory->get('cache_db'));
        $this->assertInstanceOf(MemoryBackend::class, $factory->get('cache_memory'));
    }

    #[Test]
    public function bin_factory_via_constructor_parameter(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $config = new CacheConfiguration(
            binFactories: ['cache_db' => fn() => new DatabaseBackend($pdo, 'cache_db')],
        );

        $factory = new CacheFactory($config);
        $bin = $factory->get('cache_db');

        $this->assertInstanceOf(DatabaseBackend::class, $bin);
    }
}
