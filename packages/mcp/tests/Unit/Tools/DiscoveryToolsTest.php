<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\Tools\DiscoveryTools;
use Waaseyaa\Mcp\Tools\McpTool;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\WorkflowVisibility;

#[CoversClass(DiscoveryTools::class)]
#[CoversClass(McpTool::class)]
final class DiscoveryToolsTest extends TestCase
{
    #[Test]
    public function searchTeachingsAddsDeprecatedAliasMeta(): void
    {
        $tools = $this->createDiscoveryTools();

        $result = $tools->searchTeachings(['query' => 'test']);

        self::assertSame('search_teachings', $result['meta']['deprecated_alias']);
        self::assertSame('search_entities', $result['meta']['tool']);
    }

    #[Test]
    public function searchEntitiesSetsToolMeta(): void
    {
        $tools = $this->createDiscoveryTools();

        $result = $tools->searchEntities(['query' => 'test', 'type' => 'node']);

        self::assertSame('search_entities', $result['meta']['tool']);
    }

    #[Test]
    public function aiDiscoverRequiresQuery(): void
    {
        $tools = $this->createDiscoveryTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "query"');

        $tools->aiDiscover(['query' => '', 'type' => 'node']);
    }

    #[Test]
    public function aiDiscoverRequiresType(): void
    {
        $tools = $this->createDiscoveryTools();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "type"');

        $tools->aiDiscover(['query' => 'test', 'type' => '']);
    }

    private function createDiscoveryTools(): DiscoveryTools
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $embeddingStorage->method('findSimilar')->willReturn([]);

        return new DiscoveryTools(
            entityTypeManager: $manager,
            serializer: new ResourceSerializer($manager),
            accessHandler: new EntityAccessHandler([]),
            account: $this->anonymousAccount(),
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            workflowVisibility: new WorkflowVisibility(EditorialWorkflowPreset::create()),
        );
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
