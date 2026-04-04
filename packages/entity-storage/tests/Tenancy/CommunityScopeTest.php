<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\Foundation\Community\CommunityContext;

#[CoversClass(CommunityScope::class)]
#[CoversClass(InMemoryStorageDriver::class)]
final class CommunityScopeTest extends TestCase
{
    private CommunityContext $context;
    private CommunityScope $scope;
    private InMemoryStorageDriver $driver;

    protected function setUp(): void
    {
        $this->context = new CommunityContext();
        $this->scope   = new CommunityScope($this->context);
        $this->driver  = new InMemoryStorageDriver($this->scope);
    }

    #[Test]
    public function entitiesInDifferentCommunitiesAreIsolated(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'title' => 'Community A post', 'community_id' => 'community-a']);
        $this->driver->write('post', '2', ['id' => '2', 'title' => 'Community B post', 'community_id' => 'community-b']);

        $this->context->set('community-a');

        $results = $this->driver->findBy('post');

        $this->assertCount(1, $results);
        $this->assertSame('community-a', $results[0]['community_id']);
    }

    #[Test]
    public function entitiesInActiveCommunityAreReturned(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'title' => 'Post 1', 'community_id' => 'community-a']);
        $this->driver->write('post', '2', ['id' => '2', 'title' => 'Post 2', 'community_id' => 'community-a']);
        $this->driver->write('post', '3', ['id' => '3', 'title' => 'Post 3', 'community_id' => 'community-b']);

        $this->context->set('community-a');

        $results = $this->driver->findBy('post');

        $this->assertCount(2, $results);
        foreach ($results as $row) {
            $this->assertSame('community-a', $row['community_id']);
        }
    }

    #[Test]
    public function queriesAreUnscopedWhenNoContextIsActive(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'community_id' => 'community-a']);
        $this->driver->write('post', '2', ['id' => '2', 'community_id' => 'community-b']);

        // No context set.
        $results = $this->driver->findBy('post');

        $this->assertCount(2, $results);
    }

    #[Test]
    public function readByIdRespectsCommunityScope(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'community_id' => 'community-a']);

        $this->context->set('community-b');

        $row = $this->driver->read('post', '1');

        $this->assertNull($row, 'Cross-community ID lookup must return null.');
    }

    #[Test]
    public function readByIdSucceedsWhenCommunityMatches(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'community_id' => 'community-a']);

        $this->context->set('community-a');

        $row = $this->driver->read('post', '1');

        $this->assertNotNull($row);
        $this->assertSame('1', $row['id']);
    }

    #[Test]
    public function countRespectsScope(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'community_id' => 'community-a']);
        $this->driver->write('post', '2', ['id' => '2', 'community_id' => 'community-a']);
        $this->driver->write('post', '3', ['id' => '3', 'community_id' => 'community-b']);

        $this->context->set('community-a');

        $this->assertSame(2, $this->driver->count('post'));
    }

    #[Test]
    public function contextClearMakesQueriesUnscoped(): void
    {
        $this->driver->write('post', '1', ['id' => '1', 'community_id' => 'community-a']);
        $this->driver->write('post', '2', ['id' => '2', 'community_id' => 'community-b']);

        $this->context->set('community-a');
        $this->context->clear();

        $results = $this->driver->findBy('post');

        $this->assertCount(2, $results);
    }
}
