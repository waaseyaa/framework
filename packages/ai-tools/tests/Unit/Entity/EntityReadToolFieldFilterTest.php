<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * WP02 security boundary: MCP entity.read exposes the entity's stored content
 * fields (body) but never leaks credential, internal, or field-access-forbidden
 * fields — the same boundary the JSON:API serializer enforces.
 */
#[CoversClass(EntityReadTool::class)]
final class EntityReadToolFieldFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $stored
     * @param list<string> $internalFields
     */
    private function tool(array $stored, array $internalFields = []): EntityReadTool
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('story');
        $entity->method('bundle')->willReturn('story');
        $entity->method('id')->willReturn(1);
        $entity->method('toArray')->willReturn($stored);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);
        $etm->method('resolveFieldDefinitions')->willReturn(
            $this->fieldDefinitions(array_keys($stored), $internalFields),
        );

        return new EntityReadTool($etm);
    }

    /**
     * @param list<string> $names
     * @param list<string> $internal
     * @return array<string, FieldDefinitionInterface>
     */
    private function fieldDefinitions(array $names, array $internal): array
    {
        $defs = [];
        foreach ($names as $name) {
            $def = $this->createMock(FieldDefinitionInterface::class);
            $def->method('getSetting')->willReturnCallback(
                static fn(string $key): mixed => $key === 'internal' ? \in_array($name, $internal, true) : null,
            );
            $defs[$name] = $def;
        }

        return $defs;
    }

    private function account(): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->willReturn(true);
        $account->method('id')->willReturn(0);

        return $account;
    }

    /** A handler that grants entity-level view and (optionally) forbids one field. */
    private function handler(?string $forbiddenField = null): EntityAccessHandler
    {
        $policy = new class ($forbiddenField) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly ?string $forbiddenField) {}

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
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $this->forbiddenField !== null && $fieldName === $this->forbiddenField
                    ? AccessResult::forbidden()
                    : AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    /** @return array<string, mixed> */
    private function readValues(EntityReadTool $tool, AccountInterface $account): array
    {
        $result = $tool->execute(['entity_type' => 'story', 'id' => 1], $account);
        self::assertFalse($result->isError, 'read should succeed');
        $data = $result->content[0]['data'] ?? [];

        return \is_array($data['values'] ?? null) ? $data['values'] : [];
    }

    #[Test]
    public function exposes_stored_content_fields_including_body(): void
    {
        $tool = $this->tool(['title' => 'T', 'body' => 'the body', 'status' => 1]);
        $tool->setAccessHandler($this->handler());

        $values = $this->readValues($tool, $this->account());

        self::assertArrayHasKey('title', $values);
        self::assertArrayHasKey('body', $values, 'body must be exposed — not a hardcoded subset');
        self::assertSame('the body', $values['body']);
    }

    #[Test]
    public function never_leaks_credential_fields(): void
    {
        $tool = $this->tool(['title' => 'T', 'pass' => 'x', 'password' => 'y', 'password_hash' => 'z']);
        $tool->setAccessHandler($this->handler());

        $values = $this->readValues($tool, $this->account());

        self::assertArrayHasKey('title', $values);
        self::assertArrayNotHasKey('pass', $values);
        self::assertArrayNotHasKey('password', $values);
        self::assertArrayNotHasKey('password_hash', $values);
    }

    #[Test]
    public function never_leaks_internal_setting_fields(): void
    {
        $tool = $this->tool(['title' => 'T', 'two_factor_secret' => 'totp'], internalFields: ['two_factor_secret']);
        $tool->setAccessHandler($this->handler());

        $values = $this->readValues($tool, $this->account());

        self::assertArrayHasKey('title', $values);
        self::assertArrayNotHasKey('two_factor_secret', $values);
    }

    #[Test]
    public function never_leaks_field_access_forbidden_fields(): void
    {
        $tool = $this->tool(['title' => 'T', 'body' => 'B', 'secret_note' => 'classified']);
        $tool->setAccessHandler($this->handler(forbiddenField: 'secret_note'));

        $values = $this->readValues($tool, $this->account());

        self::assertArrayHasKey('title', $values);
        self::assertArrayHasKey('body', $values);
        self::assertArrayNotHasKey('secret_note', $values);
    }
}
