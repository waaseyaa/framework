<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Tests\Unit\Client;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\NorthCloud\Client\NorthCloudCache;

/**
 * @covers \Waaseyaa\NorthCloud\Client\NorthCloudCache
 */
#[CoversClass(NorthCloudCache::class)]
final class NorthCloudCacheTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $cache = new NorthCloudCache($this->pdo);

        $this->assertNull($cache->get('https://nc.test/missing'));
    }

    #[Test]
    public function setThenGetRoundtripsValue(): void
    {
        $cache = new NorthCloudCache($this->pdo);
        $cache->set('https://nc.test/hit', '{"ok": true}');

        $this->assertSame('{"ok": true}', $cache->get('https://nc.test/hit'));
    }

    #[Test]
    public function expiredEntriesReturnNull(): void
    {
        $cache = new NorthCloudCache($this->pdo, ttl: -1);
        $cache->set('https://nc.test/hit', 'stale');

        $this->assertNull($cache->get('https://nc.test/hit'));
    }

    #[Test]
    public function canonicalizationTreatsReorderedQueryParamsAsEqual(): void
    {
        $cache = new NorthCloudCache($this->pdo);
        $cache->set('https://nc.test/search?a=1&b=2', 'cached-body');

        $this->assertSame('cached-body', $cache->get('https://nc.test/search?b=2&a=1'));
    }

    #[Test]
    public function canonicalizationTreatsReorderedArrayQueryParamsAsEqual(): void
    {
        $cache = new NorthCloudCache($this->pdo);
        $cache->set('https://nc.test/search?topics[]=a&topics[]=b', 'cached-body');

        $this->assertSame('cached-body', $cache->get('https://nc.test/search?topics[]=b&topics[]=a'));
    }

    #[Test]
    public function canonicalizationDropsFragmentsAndLowercasesHost(): void
    {
        $cache = new NorthCloudCache($this->pdo);
        $cache->set('https://NC.TEST/search?q=x#frag', 'cached-body');

        $this->assertSame('cached-body', $cache->get('https://nc.test/search?q=x'));
    }

}
