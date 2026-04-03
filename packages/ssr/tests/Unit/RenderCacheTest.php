<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\SSR\RenderCache;

#[CoversClass(RenderCache::class)]
final class RenderCacheTest extends TestCase
{
    #[Test]
    public function cachesAndRestoresSsrResponse(): void
    {
        $backend = new MemoryBackend();
        $cache = new RenderCache($backend);
        $response = new Response('<article>cached</article>', 200);
        $response->headers->set('X-Test', '1');

        $cache->set('node', 10, 'full', 'en', $response, 120);
        $cached = $cache->get('node', 10, 'full', 'en');

        $this->assertNotNull($cached);
        $this->assertSame('<article>cached</article>', $cached->getContent());
        $this->assertSame(200, $cached->getStatusCode());
        $this->assertSame('1', $cached->headers->get('X-Test'));
    }

    #[Test]
    public function keyMatchesRequiredFormat(): void
    {
        $this->assertSame('render:node:12:teaser:fr', RenderCache::buildKey('node', 12, 'teaser', 'fr'));
    }

    #[Test]
    public function invalidateEntityClearsMatchingCachedItemsByTag(): void
    {
        $backend = new MemoryBackend();
        $cache = new RenderCache($backend);

        $cache->set('node', 99, 'full', 'en', new Response('<p>n99</p>'), 300);
        $cache->set('node', 100, 'full', 'en', new Response('<p>n100</p>'), 300);

        $cache->invalidateEntity('node', 99);

        $this->assertNull($cache->get('node', 99, 'full', 'en'));
        $this->assertNotNull($cache->get('node', 100, 'full', 'en'));
    }
}
