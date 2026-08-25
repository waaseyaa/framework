<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * CHARACTERIZATION TEST — pins KNOWN-DEFECTIVE behaviour under framework #2520.
 *
 * `entity.read` and `entity.search` sit on the MCP anonymous read tier. Both
 * wrap only their repository lookup in try/catch; the field read happens later,
 * in `serialize()` / `matches()`, via `EntityValues::toCastAwareMap()`. When a
 * `FieldReadGuard` is installed and denies a Protected field, the resulting
 * `FieldReadDenied` escapes the tool boundary entirely instead of becoming an
 * `AgentToolResult` refusal. One layer up, `AgentToolRegistryBridge` catches it
 * and emits a generic sanitized `INTERNAL_ERROR` — so an anonymous caller
 * reading ordinary content gets a server-fault envelope for what is really an
 * authorization outcome.
 *
 * This test asserts the CURRENT behaviour (the throw), not the desired one. It
 * WILL FAIL BY DESIGN once the mapping is implemented. That failure is the
 * signal to update this file to the chosen contract — it is NOT a regression.
 *
 * Three candidate mappings for the fix:
 *  1. Redact the denied field — drop it from `values` (read) or from the search
 *     haystack, and return success. Matches the open-by-default polarity the
 *     `FieldAccessPolicy` filter already uses on these same tools.
 *  2. Collapse into the existing not-found response — reuse the byte-identical
 *     `"%s/%s not found"` envelope `EntityReadTool` already emits for both the
 *     absent and the view-forbidden entity.
 *  3. A distinguishable `FIELD_FORBIDDEN` code — most informative, but it
 *     REOPENS the existence oracle `EntityReadTool` deliberately closed (R8-c):
 *     a caller that can tell "exists but a field is fenced" from "absent" can
 *     enumerate ids on the anonymous tier.
 *
 * Assertions are made at the TOOL boundary (`execute()`), not at the MCP
 * endpoint: the tool's own return contract is the narrower and more durable
 * surface, it is where the fix will land, and it holds for every consumer of
 * these tools, not only the MCP bridge.
 *
 * @see \Waaseyaa\Access\FieldReadGuard
 * @see \Waaseyaa\MCP\Bridge\AgentToolRegistryBridge
 */
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntitySearchTool::class)]
final class EntityToolFieldReadDeniedCharacterizationTest extends TestCase
{
    private const string ENTITY_TYPE = 'story';

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
    public function entity_read_lets_a_denied_field_read_escape_the_tool_boundary(): void
    {
        $tool = new EntityReadTool($this->entityTypeManager());

        $this->expectException(FieldReadDenied::class);
        $this->expectExceptionMessage('Field story.body is not readable in this account context.');

        // #2520: this SHOULD be an AgentToolResult refusal. Today it throws
        // straight through execute() — serialize() runs after the try/catch.
        $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 1],
            self::principal(),
        ));
    }

    #[Test]
    public function entity_search_lets_a_denied_field_read_escape_the_tool_boundary(): void
    {
        $tool = new EntitySearchTool($this->entityTypeManager());

        $this->expectException(FieldReadDenied::class);
        $this->expectExceptionMessage('Field story.body is not readable in this account context.');

        // Same shape as entity.read: matches() runs after the try/catch that
        // only covers the findBy() lookup.
        $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'query' => 'anything'],
            self::principal(),
        ));
    }

    /**
     * The escape is specific to the DENIAL, not to the tools being broken in
     * general: flip the very same guard to an allowing decision and the very
     * same call returns an ordinary success result carrying `body`. This is
     * what makes the two tests above a pin on #2520 rather than on incidental
     * fixture wiring.
     *
     * (Uninstalling the guard is not the control: a Protected read with no
     * guard fails closed with `MissingFieldReadContext` — a different, also
     * unmapped, escape.)
     */
    #[Test]
    public function the_same_read_succeeds_when_the_guard_allows_the_field(): void
    {
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            static fn(): AccessResult => AccessResult::allowed(),
        ));
        $tool = new EntityReadTool($this->entityTypeManager());

        $result = $this->inAccountScope(static fn(): mixed => $tool->execute(
            ['entity_type' => self::ENTITY_TYPE, 'id' => 1],
            self::principal(),
        ));

        self::assertInstanceOf(AgentToolResult::class, $result);
        self::assertFalse($result->isError);
        // Shape-agnostic on purpose: #2520's sibling defect is that these tools
        // emit non-MCP content blocks, so the block shape is in flux. All this
        // control needs to show is that the guarded value was released.
        self::assertStringContainsString('the body', json_encode($result->content, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Run inside a pushed account read context, exactly as `McpEndpoint` does
     * around tool dispatch. Without it the guard raises `MissingFieldReadContext`
     * instead, which is a different (also unmapped) failure mode.
     */
    private function inAccountScope(callable $callback): mixed
    {
        return $this->scope->run(self::principal(), $callback);
    }

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $entity = $this->entity();

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $repository->method('findBy')->willReturn([$entity]);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);
        $etm->method('resolveFieldDefinitions')->willReturn($this->fieldDefinitions());

        return $etm;
    }

    /** A sealed framework entity whose `body` is Protected, so reading it activates the guard. */
    private function entity(): EntityBase
    {
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['id' => 1, 'title' => 'T', 'body' => 'the body'],
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'title' => FieldReadLevel::Public,
                'body' => FieldReadLevel::Protected,
            ]),
            structure: new EntityStructure(
                self::ENTITY_TYPE,
                self::ENTITY_TYPE,
                1,
                null,
                fieldNames: ['id', 'title', 'body'],
            ),
            entityTypeId: self::ENTITY_TYPE,
            entityKeys: ['id' => 'id', 'label' => 'title'],
        );

        return $boundary->installer()->instantiate(GuardedStoryEntity::class, $payload);
    }

    /** @return array<string, FieldDefinitionInterface> */
    private function fieldDefinitions(): array
    {
        $defs = [];
        foreach (['id', 'title', 'body'] as $name) {
            $def = $this->createStub(FieldDefinitionInterface::class);
            $def->method('getSetting')->willReturn(null);
            $defs[$name] = $def;
        }

        return $defs;
    }

    /** Capability-only mode: no access handler is attached, so the entity gate is a no-op. */
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
                return 'characterization';
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
