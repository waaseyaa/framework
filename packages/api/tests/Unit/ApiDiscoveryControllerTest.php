<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(ApiDiscoveryController::class)]
final class ApiDiscoveryControllerTest extends TestCase
{
    #[Test]
    public function discover_returns_links_for_all_entity_types_when_authenticated(): void
    {
        $manager = $this->createManagerWithArticleAndTag();

        $controller = new ApiDiscoveryController($manager, '/api', $this->authenticatedAccount());
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
        $controller = new ApiDiscoveryController($manager, account: $this->authenticatedAccount());

        $doc = $controller->discover();

        $this->assertSame(['self' => '/api'], $doc['links']);
    }

    #[Test]
    public function discover_returns_only_self_link_for_anonymous_account(): void
    {
        $manager = $this->createManagerWithArticleAndTag();

        $controller = new ApiDiscoveryController($manager, '/api', $this->anonymousAccount());
        $doc = $controller->discover();

        $this->assertSame(['self' => '/api'], $doc['links']);
    }

    #[Test]
    public function discover_returns_only_self_link_when_account_is_null(): void
    {
        $manager = $this->createManagerWithArticleAndTag();

        $controller = new ApiDiscoveryController($manager, '/api');
        $doc = $controller->discover();

        $this->assertSame(['self' => '/api'], $doc['links']);
    }

    #[Test]
    public function discover_omits_non_discoverable_types_for_authenticated_accounts(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'internal_thing',
            label: 'Internal Thing',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            discoverable: false,
        ));

        $controller = new ApiDiscoveryController($manager, '/api', $this->authenticatedAccount());
        $doc = $controller->discover();

        $this->assertArrayHasKey('article', $doc['links']);
        $this->assertArrayNotHasKey('internal_thing', $doc['links']);
        $this->assertStringNotContainsString(
            'internal_thing',
            json_encode($doc, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function discover_envelope_is_constant_across_all_caller_kinds(): void
    {
        $manager = $this->createManagerWithArticleAndTag();
        $expectedMeta = ['api' => 'waaseyaa', 'version' => '1.0'];

        foreach ([
            new ApiDiscoveryController($manager, '/api', $this->authenticatedAccount()),
            new ApiDiscoveryController($manager, '/api', $this->anonymousAccount()),
            new ApiDiscoveryController($manager, '/api'),
        ] as $controller) {
            $doc = $controller->discover();

            $this->assertSame($expectedMeta, $doc['meta']);
            $this->assertSame('/api', $doc['links']['self']);
        }
    }

    // --- Helpers ---

    private function createManagerWithArticleAndTag(): EntityTypeManager
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

        return $manager;
    }

    private function authenticatedAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function anonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }
}
