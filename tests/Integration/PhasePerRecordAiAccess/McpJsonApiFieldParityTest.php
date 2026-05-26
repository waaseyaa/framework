<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhasePerRecordAiAccess;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\Serializer\McpEntityFieldFilter;

/**
 * FR-007 parity integration test: MCP and JSON:API surface the same field-set for a given account,
 * modulo the MCP redaction marker replacing forbidden fields vs JSON:API omitting them.
 *
 * Dead-code guard: removing the McpEntityFieldFilter wiring in McpController MUST cause
 * this test to fail (the MCP response would no longer contain the redaction marker for `body`).
 */
#[CoversNothing]
final class McpJsonApiFieldParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $definition) use ($dispatcher): SqlEntityStorage {
                $schema = new SqlSchemaHandler($definition, $this->database);
                $schema->ensureTable();
                return new SqlEntityStorage($definition, $this->database, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: \Waaseyaa\Api\Tests\Fixtures\NodeContentTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'boolean'],
            ],
        ));

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
    }

    // -------------------------------------------------------------------------
    // FR-007 core parity assertion
    // -------------------------------------------------------------------------

    #[Test]
    public function mcpAndJsonApiAgreeOnFieldSetWithBodyForbiddenForAccount(): void
    {
        // Register the field access policy that forbids `body` for the restricted account.
        $restrictedAccount = new RestrictedAccount();
        $policy = new BodyForbiddenFieldPolicy();
        $accessHandler = new EntityAccessHandler([$policy]);

        // Create and persist a node entity.
        $storage = $this->entityTypeManager->getStorage('node');
        $node = $storage->create([
            'title' => 'Test Node',
            'body' => 'Restricted body content',
            'type' => 'article',
            'status' => 1,
        ]);
        $storage->save($node);

        $nodeId = $node->id();
        self::assertNotNull($nodeId, 'Node must have an ID after save.');

        // ── MCP surface ──────────────────────────────────────────────────────
        // McpController wires McpEntityFieldFilter in its constructor.
        $mcpController = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $accessHandler,
            account: $restrictedAccount,
            embeddingStorage: new NullEmbeddingStorage(),
        );

        $mcpRpc = $mcpController->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_entity',
                'arguments' => ['type' => 'node', 'id' => $nodeId],
            ],
        ]);

        // Decode MCP response payload.
        self::assertArrayHasKey('result', $mcpRpc, 'MCP must return a result (not an error).');
        $mcpText = $mcpRpc['result']['content'][0]['text'];
        $mcpData = \json_decode($mcpText, true, 512, \JSON_THROW_ON_ERROR);

        // (a) MCP includes the redaction marker in place of attributes.body (FR-006).
        self::assertArrayHasKey('data', $mcpData, 'MCP response must have data key.');
        self::assertArrayHasKey('attributes', $mcpData['data'], 'MCP data must have attributes.');
        $mcpAttributes = $mcpData['data']['attributes'];

        self::assertArrayHasKey('body', $mcpAttributes,
            'MCP must INCLUDE the `body` key (with redaction marker, not omit it) — ' .
            'removing McpEntityFieldFilter wiring would break this assertion.');
        self::assertSame(
            McpEntityFieldFilter::REDACTION_MARKER,
            $mcpAttributes['body'],
            'Forbidden field `body` must be replaced by the canonical redaction marker in MCP response.',
        );

        // ── JSON:API surface ─────────────────────────────────────────────────
        $jsonApiController = new JsonApiController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $accessHandler,
            account: $restrictedAccount,
        );

        $jsonApiDoc = $jsonApiController->show('node', (int) $nodeId);
        $jsonApiArray = $jsonApiDoc->toArray();

        // (b) JSON:API omits attributes.body entirely (existing JSON:API behaviour).
        self::assertArrayNotHasKey('errors', $jsonApiArray, 'JSON:API must not return errors.');
        self::assertArrayHasKey('data', $jsonApiArray);
        $jsonApiAttributes = $jsonApiArray['data']['attributes'] ?? [];
        self::assertArrayNotHasKey('body', $jsonApiAttributes,
            'JSON:API must OMIT the forbidden `body` field (not include it).');

        // (c) Field-set parity: fields reachable by the caller are the same on both surfaces.
        // MCP set: all fields in attributes, minus the redacted ones (those keys exist but with marker).
        // JSON:API set: all fields in attributes (forbidden fields are absent).
        // The two "visible" field keys (fields that are NOT forbidden) must be identical.
        $mcpReachableFields = array_keys(array_filter(
            $mcpAttributes,
            static fn($value): bool => $value !== McpEntityFieldFilter::REDACTION_MARKER,
        ));
        sort($mcpReachableFields);

        $jsonApiReachableFields = array_keys($jsonApiAttributes);
        sort($jsonApiReachableFields);

        self::assertSame(
            $jsonApiReachableFields,
            $mcpReachableFields,
            'The set of reachable (non-forbidden) field keys must be identical between MCP and JSON:API.',
        );

        // Sanity: title must be present and accessible on both surfaces.
        self::assertSame('Test Node', $mcpAttributes['title']);
        self::assertSame('Test Node', $jsonApiAttributes['title']);
    }

    #[Test]
    public function mcpRedactionMarkerAbsentWhenNoFieldPolicyRegistered(): void
    {
        // With no field-access policies, all fields are exposed (open-by-default).
        $account = new RestrictedAccount();
        $accessHandler = new EntityAccessHandler([new AllowAllEntityPolicy()]);

        $storage = $this->entityTypeManager->getStorage('node');
        $node = $storage->create([
            'title' => 'Open Node',
            'body' => 'Open body content',
            'type' => 'article',
            'status' => 1,
        ]);
        $storage->save($node);

        $nodeId = $node->id();
        self::assertNotNull($nodeId);

        $mcpController = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $accessHandler,
            account: $account,
            embeddingStorage: new NullEmbeddingStorage(),
        );

        $mcpRpc = $mcpController->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_entity',
                'arguments' => ['type' => 'node', 'id' => $nodeId],
            ],
        ]);

        self::assertArrayHasKey('result', $mcpRpc);
        $mcpText = $mcpRpc['result']['content'][0]['text'];
        $mcpData = \json_decode($mcpText, true, 512, \JSON_THROW_ON_ERROR);
        $mcpAttributes = $mcpData['data']['attributes'] ?? [];

        // No redaction marker anywhere — all fields are open.
        self::assertNotSame(McpEntityFieldFilter::REDACTION_MARKER, $mcpAttributes['body'] ?? null,
            'Body must not be redacted when no field policy restricts it.');
        self::assertSame('Open body content', $mcpAttributes['body']);
    }
}

// ── Test doubles ─────────────────────────────────────────────────────────────

/**
 * Field access policy that forbids the `body` field on `node` entities for view operations.
 * Entity-level access is allowed so the entity itself is retrievable.
 */
final class BodyForbiddenFieldPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // Entity-level: allow view so the entity itself is not 403'd.
        return AccessResult::allowed('Entity visible.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($fieldName === 'body' && $operation === 'view') {
            return AccessResult::forbidden('body field is restricted for this account.');
        }

        return AccessResult::neutral();
    }
}

/**
 * Entity-level allow-all policy (no field restrictions).
 */
final class AllowAllEntityPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}

/**
 * Account with no special permissions (simulates a restricted caller).
 */
final class RestrictedAccount implements AccountInterface
{
    public function id(): int|string { return 42; }
    public function hasPermission(string $permission): bool { return false; }
    public function getRoles(): array { return ['authenticated']; }
    public function isAuthenticated(): bool { return true; }
}

/**
 * Minimal no-op embedding storage — McpController requires one but it is
 * unused for `get_entity` calls.
 */
final class NullEmbeddingStorage implements EmbeddingStorageInterface
{
    /** @param list<float|int> $vector */
    public function store(string $entityType, string $id, array $vector): void {}

    /** @param list<float|int> $queryVector */
    public function findSimilar(array $queryVector, string $entityType, int $limit): array { return []; }

    public function delete(string $entityType, string $id): void {}
}
