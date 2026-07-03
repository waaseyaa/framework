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
use Waaseyaa\AI\Tools\Entity\EntitySearchTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * R4 PR1 WP1 (security, audit-remediation batch 2026-07-03): entity.search
 * gates entity-level view via canViewEntity() but must ALSO apply the same
 * field-level redaction EntityReadTool applies before substring-matching a
 * needle against an entity's values — otherwise a field-access-forbidden,
 * internal, or credential field becomes a content oracle: a caller who
 * cannot see the field can still learn its content by observing match/no-
 * match on a needle unique to that field.
 */
#[CoversClass(EntitySearchTool::class)]
final class EntitySearchToolFieldFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $stored
     * @param list<string> $internalFields
     */
    private function tool(array $stored, array $internalFields = []): EntitySearchTool
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('story');
        $entity->method('bundle')->willReturn('story');
        $entity->method('id')->willReturn(1);
        $entity->method('toArray')->willReturn($stored);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('findBy')->willReturn([$entity]);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);
        $etm->method('resolveFieldDefinitions')->willReturn(
            $this->fieldDefinitions(array_keys($stored), $internalFields),
        );

        return new EntitySearchTool($etm);
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

    /** @param list<string> $permissions */
    private function account(array $permissions = ['tool.entity.search']): AccountInterface
    {
        return new class($permissions) implements AccountInterface {
            /** @param list<string> $permissions */
            public function __construct(private readonly array $permissions) {}

            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, $this->permissions, true);
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

    /** A handler that grants entity-level view and (optionally) forbids one field unless the account holds an unlock permission. */
    private function handler(?string $forbiddenField = null, string $unlockPermission = 'view_secret'): EntityAccessHandler
    {
        $policy = new class ($forbiddenField, $unlockPermission) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly ?string $forbiddenField,
                private readonly string $unlockPermission,
            ) {}

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
                if ($this->forbiddenField !== null && $fieldName === $this->forbiddenField && !$account->hasPermission($this->unlockPermission)) {
                    return AccessResult::forbidden();
                }

                return AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    /** @return array<string, mixed> */
    private function search(EntitySearchTool $tool, string $query, AccountInterface $account): array
    {
        $result = $tool->execute(['entity_type' => 'story', 'query' => $query], $account);
        self::assertFalse($result->isError, 'search should succeed');

        return $result->content[0]['data'] ?? [];
    }

    #[Test]
    public function never_matches_a_field_access_forbidden_field(): void
    {
        $tool = $this->tool(['title' => 'A story', 'secret_note' => 'zorblatt-classified-9000']);
        $tool->setAccessHandler($this->handler(forbiddenField: 'secret_note'));

        $deniedData = $this->search($tool, 'zorblatt-classified-9000', $this->account(['tool.entity.search']));
        self::assertSame(0, $deniedData['count'], 'account without field access must not match on the forbidden field');

        $allowedData = $this->search($tool, 'zorblatt-classified-9000', $this->account(['tool.entity.search', 'view_secret']));
        self::assertSame(1, $allowedData['count'], 'account with field access still finds the match — search is not broken');
    }

    #[Test]
    public function never_matches_an_internal_setting_field(): void
    {
        $tool = $this->tool(['title' => 'A story', 'two_factor_secret' => 'totp-unique-value'], internalFields: ['two_factor_secret']);
        $tool->setAccessHandler($this->handler());

        $data = $this->search($tool, 'totp-unique-value', $this->account());
        self::assertSame(0, $data['count'], 'internal-marked fields must never be part of the search haystack');
    }

    #[Test]
    public function never_matches_an_always_internal_credential_field(): void
    {
        $tool = $this->tool(['title' => 'A story', 'password' => 'hunter2-unique-value']);
        $tool->setAccessHandler($this->handler());

        $data = $this->search($tool, 'hunter2-unique-value', $this->account());
        self::assertSame(0, $data['count'], 'credential fields must never be part of the search haystack');
    }

    #[Test]
    public function still_matches_non_restricted_fields(): void
    {
        $tool = $this->tool(['title' => 'A findable story', 'secret_note' => 'classified']);
        $tool->setAccessHandler($this->handler(forbiddenField: 'secret_note'));

        $data = $this->search($tool, 'findable', $this->account(['tool.entity.search']));
        self::assertSame(1, $data['count'], 'search still works for non-restricted content');
    }
}
