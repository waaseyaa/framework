<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Entity\EntityFieldRedaction;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * #2520: `FieldReadDenied` at the entity-tool boundary maps the same way
 * JSON:API already does — omit the denied field on a view-authorized entity,
 * never `INTERNAL_ERROR`, never a distinguishable field-forbidden envelope.
 *
 * `entity.read` and `entity.search` sit on the MCP anonymous read tier. Both
 * project stored fields through `EntityValues::toCastAwareMap()` after the
 * legacy `FieldAccessPolicy` filter. A Neutral policy still lets a Protected
 * name through; the WP4 `FieldReadGuard` then denies the accessor. Catching
 * that per field and omitting it preserves fail-closed redaction, keeps the
 * R8-c existence-oracle closure (absent and view-forbidden stay the identical
 * not-found envelope), and does not name the inaccessible field.
 *
 * @see \Waaseyaa\Api\ResourceSerializer::attributesFromEntity()
 * @see \Waaseyaa\Access\FieldReadGuard
 */
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntitySearchTool::class)]
#[CoversClass(EntityFieldRedaction::class)]
final class EntityToolFieldReadDeniedMappingTest extends TestCase
{
    private const string ENTITY_TYPE = 'story';

    /** Unique to the Protected `workflow_state` on the published-page fixture. */
    private const string PROTECTED_NEEDLE = 'editorial-secret-needle';

    /** The process-global guard is restored in tearDown so siblings are unaffected. */
    private ?EntityValueReadGuardInterface $priorGuard = null;

    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        $this->priorGuard = EntityReadRuntime::guard();
        $this->scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            static fn(): AccessResult => AccessResult::forbidden('No ambient protected-field grant.'),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard($this->priorGuard);
    }

    #[Test]
    public function entity_read_omits_a_denied_protected_field_and_returns_success(): void
    {
        $tool = new EntityReadTool($this->entityTypeManager($this->entityWithProtectedBody()));

        $result = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 1],
            self::principal(),
        ));

        self::assertInstanceOf(AgentToolResult::class, $result);
        $this->assertMappedSuccess($result);
        $values = $this->values($result);
        self::assertSame('T', $values['title']);
        self::assertArrayNotHasKey('body', $values);
        self::assertStringNotContainsString('the body', $this->wireText($result));
    }

    #[Test]
    public function entity_search_omits_denied_protected_fields_from_the_haystack_and_returns_success(): void
    {
        $tool = new EntitySearchTool($this->entityTypeManager($this->entityWithProtectedBody()));

        $miss = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => 'the body'],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $miss);
        $this->assertMappedSuccess($miss);
        self::assertSame(0, $miss->structuredContent['count'] ?? null);

        $hit = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => 'T'],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $hit);
        $this->assertMappedSuccess($hit);
        self::assertSame(1, $hit->structuredContent['count'] ?? null);
    }

    #[Test]
    public function anonymous_published_content_read_returns_public_fields_and_omits_protected_ones(): void
    {
        $tool = new EntityReadTool($this->entityTypeManager($this->publishedPage()));

        $result = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 7],
            self::principal(),
        ));

        self::assertInstanceOf(AgentToolResult::class, $result);
        $this->assertMappedSuccess($result);
        $values = $this->values($result);
        self::assertSame('Published history', $values['title']);
        self::assertSame('the public body', $values['body']);
        self::assertArrayNotHasKey('status', $values);
        self::assertArrayNotHasKey('uid', $values);
        self::assertArrayNotHasKey('workflow_state', $values);
        $wire = $this->wireText($result);
        self::assertStringNotContainsString(self::PROTECTED_NEEDLE, $wire);
        self::assertStringNotContainsString('Field story.', $wire);
    }

    #[Test]
    public function anonymous_published_content_search_matches_public_text_and_not_protected_text(): void
    {
        $tool = new EntitySearchTool($this->entityTypeManager($this->publishedPage()));

        $titleHit = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => 'Published history'],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $titleHit);
        $this->assertMappedSuccess($titleHit);
        self::assertSame(1, $titleHit->structuredContent['count'] ?? null);
        self::assertSame(
            [['entity_type' => self::ENTITY_TYPE, 'id' => 7]],
            $titleHit->structuredContent['items'] ?? null,
        );

        $bodyHit = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => 'the public body'],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $bodyHit);
        $this->assertMappedSuccess($bodyHit);
        self::assertSame(1, $bodyHit->structuredContent['count'] ?? null);

        $protectedMiss = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => self::PROTECTED_NEEDLE],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $protectedMiss);
        $this->assertMappedSuccess($protectedMiss);
        self::assertSame(0, $protectedMiss->structuredContent['count'] ?? null);
        self::assertSame([], $protectedMiss->structuredContent['items'] ?? null);
    }

    #[Test]
    public function entity_read_still_collapses_absent_and_view_forbidden_to_the_same_not_found(): void
    {
        $entity = $this->publishedPage();
        $tool = new EntityReadTool($this->entityTypeManager($entity, missingFind: true));
        $tool->setAccessHandler($this->denyViewHandler());

        $absent = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 7],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $absent);

        $forbiddenTool = new EntityReadTool($this->entityTypeManager($entity));
        $forbiddenTool->setAccessHandler($this->denyViewHandler());
        $forbidden = $this->inAccountScope(static fn(): mixed => $forbiddenTool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 7],
            self::principal(),
        ));
        self::assertInstanceOf(AgentToolResult::class, $forbidden);

        $expected = sprintf('entity.read: %s/7 not found', self::ENTITY_TYPE);
        self::assertTrue($absent->isError);
        self::assertTrue($forbidden->isError);
        self::assertSame($absent->summary, $forbidden->summary);
        self::assertSame($expected, $absent->summary);
        self::assertSame($absent->content, $forbidden->content);
        self::assertStringNotContainsString('INTERNAL_ERROR', $this->wireText($absent));
        self::assertStringNotContainsString('INTERNAL_ERROR', $this->wireText($forbidden));
        self::assertStringNotContainsString(self::PROTECTED_NEEDLE, $this->wireText($forbidden));
    }

    /**
     * The mapping is specific to the DENIAL: flip the same guard to allowing
     * and the same call releases the Protected value. Uninstalling the guard
     * is not the control — a Protected read with no guard fails closed with
     * `MissingFieldReadContext`.
     */
    #[Test]
    public function the_same_read_succeeds_when_the_guard_allows_the_field(): void
    {
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            static fn(): AccessResult => AccessResult::allowed(),
        ));
        $tool = new EntityReadTool($this->entityTypeManager($this->entityWithProtectedBody()));

        $result = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 1],
            self::principal(),
        ));

        self::assertInstanceOf(AgentToolResult::class, $result);
        self::assertFalse($result->isError);
        self::assertSame('the body', $this->values($result)['body'] ?? null);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertMappedSuccess(AgentToolResult $result): void
    {
        self::assertFalse($result->isError, 'a view-authorized entity must not become a tool error');
        $wire = $this->wireText($result);
        self::assertStringNotContainsString('INTERNAL_ERROR', $wire);
        self::assertStringNotContainsString('FIELD_FORBIDDEN', $wire);
        self::assertStringNotContainsString('not readable in this account context', $wire);
    }

    /** @return array<string, mixed> */
    private function values(AgentToolResult $result): array
    {
        $data = $result->structuredContent ?? [];
        $values = $data['values'] ?? [];

        return \is_array($values) ? $values : [];
    }

    private function wireText(AgentToolResult $result): string
    {
        $chunks = [$result->summary ?? ''];
        foreach ($result->content as $block) {
            if (isset($block['text']) && \is_string($block['text'])) {
                $chunks[] = $block['text'];
            }
        }
        $chunks[] = json_encode($result->structuredContent, JSON_THROW_ON_ERROR);

        return implode("\n", $chunks);
    }

    /**
     * Run inside a pushed account read context, exactly as `McpEndpoint` does
     * around tool dispatch. Without it the guard raises `MissingFieldReadContext`
     * instead, which is a different (also unmapped) failure mode.
     */
    private function inAccountScope(callable $callback): mixed
    {
        return $this->scope->run(self::principal(), $callback);
    }

    private function entityTypeManager(EntityBase $entity, bool $missingFind = false): EntityTypeManagerInterface
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($missingFind ? null : $entity);
        $repository->method('findBy')->willReturn($missingFind ? [] : [$entity]);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);
        $etm->method('resolveFieldDefinitions')->willReturn(
            $this->fieldDefinitions($entity->fieldNames()),
        );

        return $etm;
    }

    private function denyViewHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('unpublished to this caller');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    /** A sealed entity whose `body` is Protected, so reading it activates the guard. */
    private function entityWithProtectedBody(): EntityBase
    {
        return $this->seal(
            values: ['id' => 1, 'title' => 'T', 'body' => 'the body'],
            layout: [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'body' => FieldReadLevel::Protected,
            ],
        );
    }

    /**
     * Node-shaped published page: Public title/body plus Protected status/uid/
     * workflow_state — the layout that made anonymous `entity.read` of an
     * ordinary published page explode as `INTERNAL_ERROR`.
     */
    private function publishedPage(): EntityBase
    {
        return $this->seal(
            values: [
                'id' => 7,
                'title' => 'Published history',
                'body' => 'the public body',
                'status' => true,
                'uid' => 42,
                'workflow_state' => self::PROTECTED_NEEDLE,
            ],
            layout: [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'body' => FieldReadLevel::Public,
                'status' => FieldReadLevel::Protected,
                'uid' => FieldReadLevel::Protected,
                'workflow_state' => FieldReadLevel::Protected,
            ],
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, FieldReadLevel> $layout
     */
    private function seal(array $values, array $layout): EntityBase
    {
        $boundary = new EntityInitializationBoundary();
        $fieldNames = array_keys($layout);
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), $layout),
            structure: new EntityStructure(
                self::ENTITY_TYPE,
                self::ENTITY_TYPE,
                $values['id'] ?? null,
                null,
                fieldNames: $fieldNames,
            ),
            entityTypeId: self::ENTITY_TYPE,
            entityKeys: ['id' => 'id', 'label' => 'title'],
        );

        return $boundary->installer()->instantiate(GuardedStoryEntity::class, $payload);
    }

    /**
     * @param list<string> $names
     * @return array<string, FieldDefinitionInterface>
     */
    private function fieldDefinitions(array $names): array
    {
        $defs = [];
        foreach ($names as $name) {
            $def = $this->createStub(FieldDefinitionInterface::class);
            $def->method('getSetting')->willReturn(null);
            $defs[$name] = $def;
        }

        return $defs;
    }

    /** Capability-only mode unless a test attaches a handler: the entity gate is a no-op allow. */
    private static function principal(): AuthorizationPrincipalInterface
    {
        static $principal = null;

        return $principal ??= new class implements AuthorizationPrincipalInterface {
            public function id(): int|string
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'tool.entity.read' || $permission === 'tool.entity.search';
            }

            public function getRoles(): array
            {
                return ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }

            public function claimsGeneration(): string
            {
                return 'field-read-denied-mapping';
            }

            public function tenantId(): ?string
            {
                return null;
            }

            public function communityId(): ?string
            {
                return null;
            }
        };
    }
}

final class GuardedStoryEntity extends EntityBase {}
