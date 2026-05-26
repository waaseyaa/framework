<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\Serializer\McpEntityFieldFilter;
use Waaseyaa\Mcp\Tools\EntityTools;
use Waaseyaa\Mcp\Tools\McpTool;

#[CoversClass(EntityTools::class)]
#[CoversClass(McpTool::class)]
final class EntityToolsTest extends TestCase
{
    #[Test]
    public function listEntityTypesReturnsDefinitions(): void
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Content',
            class: \Waaseyaa\Entity\EntityBase::class,
            storageClass: \Waaseyaa\Entity\Storage\SqlEntityStorage::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['node' => $definition]);

        $tools = new EntityTools(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            accessHandler: new EntityAccessHandler([]),
            account: $this->anonymousAccount(),
        );

        $result = $tools->listEntityTypes();

        self::assertArrayHasKey('data', $result);
        self::assertCount(1, $result['data']);
        self::assertSame('node', $result['data'][0]['id']);
        self::assertSame('Content', $result['data'][0]['label']);
    }

    #[Test]
    public function listEntityTypesReturnsEmptyWhenNoDefinitions(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $tools = new EntityTools(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            accessHandler: new EntityAccessHandler([]),
            account: $this->anonymousAccount(),
        );

        $result = $tools->listEntityTypes();

        self::assertSame(['data' => []], $result);
    }

    #[Test]
    public function setFieldFilterWiresFieldAccessEnforcement(): void
    {
        // Verify that setFieldFilter() is callable and accepted — the field filter
        // wiring is exercised end-to-end by the integration test (McpJsonApiFieldParityTest).
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'body' && $operation === 'view') {
                    return AccessResult::forbidden('Body restricted for anonymous.');
                }
                return AccessResult::neutral();
            }
        };

        $accessHandler = new EntityAccessHandler([$policy]);
        $tools = new EntityTools(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            accessHandler: $accessHandler,
            account: $this->anonymousAccount(),
        );

        // Wire the field filter — must not throw.
        $tools->setFieldFilter(new McpEntityFieldFilter($accessHandler));

        // listEntityTypes() is unaffected by field filtering.
        $result = $tools->listEntityTypes();
        self::assertSame(['data' => []], $result);
    }

    private function anonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string { return 0; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['anonymous']; }
            public function isAuthenticated(): bool { return false; }
        };
    }
}
