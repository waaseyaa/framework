<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Entity\EntityListRevisionsTool;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\AI\Tools\Relationship\RelationshipTraverseTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\AI\Tools\Vector\VectorSearchTool;
use Waaseyaa\Entity\EntityType;

/**
 * MCP wire conformance (#2520) for the five tools on the anonymous read tier
 * ({@see \Waaseyaa\MCP\Auth\PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES}).
 *
 * These tools used to emit `['type' => 'json', 'data' => ...]`. `json` is not
 * an MCP content type — the spec defines exactly text / image / audio /
 * resource / resource_link — and
 * {@see \Waaseyaa\MCP\Bridge\AgentToolRegistryBridge::toolResultToMcpEnvelope()}
 * forwards `$result->content` to the wire verbatim, so a conforming client
 * received a block it is required to reject. The payload now rides in a `text`
 * block, with the same structure repeated in `structuredContent` for machine
 * consumers.
 *
 * Driven through the real `execute()` and asserted on the real
 * `AgentToolResult`, so the pin cannot be satisfied by a hand-built envelope.
 */
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntitySearchTool::class)]
#[CoversClass(EntityListRevisionsTool::class)]
#[CoversClass(VectorSearchTool::class)]
#[CoversClass(RelationshipTraverseTool::class)]
final class AnonymousTierMcpContentBlockTest extends TestCase
{
    /** The complete set of MCP content-block types. */
    private const MCP_CONTENT_TYPES = ['text', 'image', 'audio', 'resource', 'resource_link'];

    /** A lone continuation byte: valid in storage, not encodable as JSON. */
    private const INVALID_UTF8 = "\x80";

    #[Test]
    #[DataProvider('anonymousTierTools')]
    public function the_result_carries_only_mcp_content_blocks(string $toolName): void
    {
        $result = $this->execute($toolName);

        self::assertFalse($result->isError, sprintf('%s must succeed for this fixture', $toolName));
        self::assertNotSame([], $result->content, sprintf('%s emitted no content block', $toolName));

        foreach ($result->content as $index => $block) {
            $type = $block['type'] ?? null;
            self::assertNotSame(
                'json',
                $type,
                sprintf('%s content block %d still uses the non-MCP `json` type', $toolName, $index),
            );
            self::assertContains(
                $type,
                self::MCP_CONTENT_TYPES,
                sprintf('%s content block %d has a type outside the MCP set', $toolName, $index),
            );
        }
    }

    #[Test]
    #[DataProvider('anonymousTierTools')]
    public function the_payload_is_readable_as_text_and_as_structured_content(string $toolName): void
    {
        $result = $this->execute($toolName);

        $texts = [];
        foreach ($result->content as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text']) && is_string($block['text'])) {
                $texts[] = $block['text'];
            }
        }
        self::assertCount(1, $texts, sprintf('%s must carry exactly one text block', $toolName));

        self::assertNotNull(
            $result->structuredContent,
            sprintf('%s must repeat its payload in structuredContent', $toolName),
        );
        self::assertSame(
            $result->structuredContent,
            json_decode($texts[0], true, 512, JSON_THROW_ON_ERROR),
            sprintf('%s text block and structuredContent must be the same payload', $toolName),
        );
    }

    /** @return iterable<string, array{string}> */
    /**
     * A stored value that is not valid UTF-8 makes json_encode throw, which is
     * a live path rather than a defensive one: these payloads carry arbitrary
     * stored field values, and migrated content routinely holds bytes no one
     * validated. The encode failure must surface as the sanitized tool error —
     * never as an escaping exception, which the bridge would turn into the same
     * uninformative INTERNAL_ERROR this issue is about.
     */
    /**
     * `entity.search` is deliberately absent: its payload is
     * `[['entity_type' => ..., 'id' => ...], ...]` plus a count — a registered
     * type id and an entity key, both framework-controlled — so no stored value
     * reaches its encoder and its guard cannot fire from data. Contriving a
     * trigger would prove nothing about the path a consumer can reach.
     *
     * @return iterable<string, array{string}>
     */
    public static function toolsCarryingStoredValues(): iterable
    {
        yield 'entity.read' => ['entity.read'];
        yield 'entity.list_revisions' => ['entity.list_revisions'];
        yield 'vector.search' => ['vector.search'];
        yield 'relationship.traverse' => ['relationship.traverse'];
    }

    #[Test]
    #[DataProvider('toolsCarryingStoredValues')]
    public function an_unencodable_stored_value_returns_the_sanitized_tool_error(string $toolName): void
    {
        $result = $this->executeWithUnencodableValue($toolName);

        self::assertTrue($result->isError, sprintf('%s reported an unencodable payload as success', $toolName));

        // Pin the specific arm: only internalError() emits the sanitized
        // INTERNAL_ERROR envelope, so this cannot be satisfied by the
        // not-found or access refusals that share isError.
        $decoded = json_decode($result->content[0]['text'] ?? '{}', true);
        self::assertSame('INTERNAL_ERROR', $decoded['code'] ?? null, sprintf('%s did not reach the encode guard', $toolName));

        self::assertStringNotContainsString(
            self::INVALID_UTF8,
            json_encode($result->content, JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
            'The offending bytes must not reach the caller.',
        );
    }

    private function executeWithUnencodableValue(string $toolName): AgentToolResult
    {
        $poisoned = 'a findable story ' . self::INVALID_UTF8;

        return match ($toolName) {
            'entity.read' => new EntityReadTool($this->entityEtm($poisoned))->execute(
                ['entity_type' => 'tool_test', 'id' => '1'],
                $this->account(['tool.entity.read']),
            ),
            'entity.list_revisions' => new EntityListRevisionsTool($this->revisionEtm($poisoned))->execute(
                ['entity_type' => 'tool_test', 'id' => '1'],
                $this->account(['tool.entity.read']),
            ),
            'vector.search' => $this->vectorTool($poisoned)->execute(
                ['query' => 'findable'],
                $this->account(['tool.vector.search']),
            ),
            'relationship.traverse' => new RelationshipTraverseTool($this->relationshipEtm($poisoned))->execute(
                ['source_entity_type' => 'relationship', 'source_id' => '10'],
                $this->account(['tool.relationship.traverse']),
            ),
            default => throw new \LogicException('Unknown tool ' . $toolName),
        };
    }

    public static function anonymousTierTools(): iterable
    {
        yield 'entity.read' => ['entity.read'];
        yield 'entity.search' => ['entity.search'];
        yield 'entity.list_revisions' => ['entity.list_revisions'];
        yield 'vector.search' => ['vector.search'];
        yield 'relationship.traverse' => ['relationship.traverse'];
    }

    private function execute(string $toolName): AgentToolResult
    {
        return match ($toolName) {
            'entity.read' => new EntityReadTool($this->entityEtm())->execute(
                ['entity_type' => 'tool_test', 'id' => '1'],
                $this->account(['tool.entity.read']),
            ),
            'entity.search' => new EntitySearchTool($this->entityEtm())->execute(
                ['entity_type' => 'tool_test', 'query' => 'findable'],
                $this->account(['tool.entity.search']),
            ),
            'entity.list_revisions' => new EntityListRevisionsTool($this->revisionEtm())->execute(
                ['entity_type' => 'tool_test', 'id' => '1'],
                $this->account(['tool.entity.read']),
            ),
            'vector.search' => $this->vectorTool()->execute(
                ['query' => 'findable'],
                $this->account(['tool.vector.search']),
            ),
            'relationship.traverse' => new RelationshipTraverseTool($this->relationshipEtm())->execute(
                ['source_entity_type' => 'relationship', 'source_id' => '10'],
                $this->account(['tool.relationship.traverse']),
            ),
            default => throw new \LogicException('Unknown tool ' . $toolName),
        };
    }

    private function entityEtm(string $title = 'a findable story'): SingleTypeEntityTypeManager
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => $title, 'revision_id' => 1]));

        return new SingleTypeEntityTypeManager($this->entityType('tool_test'), $repo);
    }

    private function revisionEtm(string $title = 'v2'): SingleTypeEntityTypeManager
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => $title, 'revision_id' => 2]));
        $repo->revisions = [
            new ToolTestEntity(['id' => '1', 'title' => $title, 'revision_id' => 2, 'is_current' => true]),
            new ToolTestEntity(['id' => '1', 'title' => 'v1', 'revision_id' => 1]),
        ];

        return new SingleTypeEntityTypeManager($this->entityType('tool_test'), $repo);
    }

    private function relationshipEtm(string $toId = '1'): SingleTypeEntityTypeManager
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity([
            'id' => '1',
            'from_entity_type' => 'relationship', 'from_entity_id' => '10',
            'to_entity_type' => 'relationship', 'to_entity_id' => $toId,
        ]));

        return new SingleTypeEntityTypeManager($this->entityType('relationship'), $repo);
    }

    private function vectorTool(string $title = 'a findable story'): VectorSearchTool
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => $title]));
        $etm = new SingleTypeEntityTypeManager($this->entityType('node'), $repo);

        $embedding = new \stdClass();
        $embedding->entityTypeId = 'node';
        $embedding->entityId = '1';
        $embedding->metadata = ['title' => $title];
        $hit = new \stdClass();
        $hit->embedding = $embedding;
        $hit->score = 0.9;

        // Duck-typed doubles, exactly as the tool consumes them (it never
        // imports the ai-vector value objects).
        $provider = new class {
            /** @return list<float> */
            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }
        };
        $storage = new class ([$hit]) {
            /** @param list<object> $results */
            public function __construct(private readonly array $results) {}

            /** @param list<float> $vector */
            public function search(array $vector, int $limit): array
            {
                return $this->results;
            }
        };

        return new VectorSearchTool($etm, fn(): object => $provider, fn(): object => $storage);
    }

    private function entityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: 'Tool Test',
            class: ToolTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
    }

    /** @param list<string> $permissions */
    private function account(array $permissions): AccountInterface
    {
        return new class ($permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
