<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Node\Node;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * WP03 FR-006: a published content item is reachable at /{entityTypeId}/{id}
 * with no hand-created alias, scoped to the `content` group so non-content
 * types are never served by guessing an id.
 */
#[CoversClass(SsrPageHandler::class)]
final class CanonicalContentPathTest extends TestCase
{
    private function handler(): SsrPageHandler
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $etm->registerEntityType(new EntityType(id: 'story', label: 'Story', class: Node::class, group: 'content'));
        $etm->registerEntityType(new EntityType(id: 'profile', label: 'Profile', class: Node::class, group: 'people'));

        $db = DBALDatabase::createSqlite();

        return new SsrPageHandler(
            entityTypeManager: $etm,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($etm, $db),
            projectRoot: '/tmp/test-project',
            config: [],
        );
    }

    private function resolve(string $path): ?array
    {
        $method = new \ReflectionMethod(SsrPageHandler::class, 'resolveCanonicalContentPath');

        return $method->invoke($this->handler(), $path);
    }

    #[Test]
    public function resolves_a_content_type_system_path(): void
    {
        self::assertSame(['story', '5'], $this->resolve('/story/5'));
        self::assertSame(['story', 'abc-uuid'], $this->resolve('/story/abc-uuid'));
    }

    #[Test]
    public function does_not_resolve_non_content_group_types(): void
    {
        self::assertNull($this->resolve('/profile/1'), 'people-group types must not be served by id');
    }

    #[Test]
    public function does_not_resolve_unknown_types(): void
    {
        self::assertNull($this->resolve('/ghost/1'));
    }

    #[Test]
    public function rejects_non_canonical_shapes(): void
    {
        self::assertNull($this->resolve('/'), 'root');
        self::assertNull($this->resolve('/story'), 'no id');
        self::assertNull($this->resolve('/story/5/extra'), 'deeper than type/id');
        self::assertNull($this->resolve('/story/'), 'empty id');
    }
}
