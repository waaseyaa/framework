<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ApiDiscoveryController::class)]
final class ApiDiscoveryControllerTest extends TestCase
{
    #[Test]
    public function discover_returns_links_for_all_entity_types(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'tag',
            label: 'Tag',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        $controller = new ApiDiscoveryController($manager, '/api');
        $doc = $controller->discover();

        $this->assertSame('waaseyaa', $doc['meta']['api']);
        $this->assertArrayHasKey('article', $doc['links']);
        $this->assertSame('/api/article', $doc['links']['article']['href']);
        $this->assertArrayHasKey('tag', $doc['links']);
        $this->assertSame('/api/tag', $doc['links']['tag']['href']);
        $this->assertArrayHasKey('self', $doc['links']);
    }

    #[Test]
    public function discover_returns_empty_links_when_no_entity_types(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $controller = new ApiDiscoveryController($manager);

        $doc = $controller->discover();

        $this->assertSame(['self' => '/api'], $doc['links']);
    }
}
