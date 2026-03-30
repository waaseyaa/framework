<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestMcpAccount;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestNodeUpdatePolicy;

#[CoversClass(McpController::class)]
final class McpControllerEditorialTest extends TestCase
{
    #[Test]
    public function editorialPublishTransitionsNodeWhenAuthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'publish article content'],
            roles: ['reviewer'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 19,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_publish',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('published', $payload['data']['workflow_state']);
        $this->assertSame(1, $payload['data']['status']);
        $this->assertSame('publish', $payload['data']['workflow_last_transition']['id']);
    }

    #[Test]
    public function editorialArchiveTransitionsNodeWhenAuthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'archive article content'],
            roles: ['editor'],
            workflowState: 'published',
            status: 1,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_archive',
                'arguments' => ['type' => 'node', 'id' => 1],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('archived', $payload['data']['workflow_state']);
        $this->assertSame(0, $payload['data']['status']);
        $this->assertSame('archive', $payload['data']['workflow_last_transition']['id']);
    }

    #[Test]
    public function editorialTransitionReturnsExecutionErrorWhenUnauthorized(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_transition',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'published'],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('publish article content', $response['error']['message']);
    }

    #[Test]
    public function editorialValidateReturnsDeterministicViolationsWithoutMutatingState(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_validate',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'published'],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertFalse($payload['data']['is_valid']);
        $this->assertSame('review', $payload['data']['workflow_state']);
        $this->assertSame('published', $payload['data']['requested_state']);
        $this->assertNotEmpty($payload['data']['violations']);
    }

    #[Test]
    public function editorialTransitionRequiresKnownTargetState(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content', 'publish article content'],
            roles: ['reviewer'],
            workflowState: 'review',
            status: 0,
        );

        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'tools/call',
            'params' => [
                'name' => 'editorial_transition',
                'arguments' => ['type' => 'node', 'id' => 1, 'to_state' => 'inbox'],
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('Unknown editorial workflow state', $response['error']['message']);
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    private function createEditorialController(
        array $permissions,
        array $roles,
        string $workflowState,
        int $status,
    ): McpController {
        $nodeDefinition = new EntityType(
            id: 'node',
            label: 'Node',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [],
        );

        $nodeStorage = new InMemoryEntityStorage('node');
        $entity = $nodeStorage->create([
            'type' => 'article',
            'title' => 'Editorial Node',
            'status' => $status,
            'workflow_state' => $workflowState,
        ]);
        $nodeStorage->save($entity);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $entityTypeId): bool => $entityTypeId === 'node');
        $manager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeStorage
            : throw new \RuntimeException('Unknown storage'));
        $manager->method('getDefinition')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeDefinition
            : throw new \RuntimeException('Unknown definition'));
        $manager->method('getDefinitions')->willReturn(['node' => $nodeDefinition]);

        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $account = new TestMcpAccount(
            userId: 5,
            permissions: $permissions,
            roles: $roles,
            authenticated: true,
        );
        $access = new EntityAccessHandler([new TestNodeUpdatePolicy()]);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $access,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decodeToolPayload(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertArrayHasKey(0, $response['result']['content']);
        $this->assertArrayHasKey('text', $response['result']['content'][0]);

        return json_decode((string) $response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
