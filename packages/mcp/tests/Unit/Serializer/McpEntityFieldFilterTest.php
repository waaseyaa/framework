<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Serializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Mcp\Serializer\McpEntityFieldFilter;

#[CoversClass(McpEntityFieldFilter::class)]
final class McpEntityFieldFilterTest extends TestCase
{
    #[Test]
    public function forbiddenFieldIsReplacedWithRedactionMarker(): void
    {
        $policy = $this->policyForbiddingField('body');
        $handler = new EntityAccessHandler([$policy]);
        $filter = new McpEntityFieldFilter($handler);

        $entity = $this->makeEntity('node', ['title' => 'Hello', 'body' => 'Secret content', 'status' => true]);
        $serialized = [
            'type' => 'node',
            'id' => 'abc-123',
            'attributes' => [
                'title' => 'Hello',
                'body' => 'Secret content',
                'status' => true,
            ],
        ];

        $result = $filter->applyTo($serialized, $entity, $this->anonymousAccount());

        self::assertSame('Hello', $result['attributes']['title']);
        self::assertSame(true, $result['attributes']['status']);
        self::assertSame(McpEntityFieldFilter::REDACTION_MARKER, $result['attributes']['body']);
        self::assertTrue($result['attributes']['body']['accessRestricted']);
        self::assertSame('field_forbidden_for_account', $result['attributes']['body']['reason']);
    }

    #[Test]
    public function allowedAndNeutralFieldsArePreservedAsIs(): void
    {
        // All-neutral policy: no field is forbidden.
        $policy = $this->policyForbiddingField('nonexistent_field');
        $handler = new EntityAccessHandler([$policy]);
        $filter = new McpEntityFieldFilter($handler);

        $entity = $this->makeEntity('node', ['title' => 'Hello', 'body' => 'Open content']);
        $serialized = [
            'type' => 'node',
            'id' => 'abc-123',
            'attributes' => [
                'title' => 'Hello',
                'body' => 'Open content',
            ],
        ];

        $result = $filter->applyTo($serialized, $entity, $this->anonymousAccount());

        self::assertSame('Hello', $result['attributes']['title']);
        self::assertSame('Open content', $result['attributes']['body']);
    }

    #[Test]
    public function multipleFieldsForbiddenAllRedacted(): void
    {
        // Policy forbids both 'body' and 'secret'.
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
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if (\in_array($fieldName, ['body', 'secret'], true) && $operation === 'view') {
                    return AccessResult::forbidden('Field restricted.');
                }
                return AccessResult::neutral();
            }
        };

        $handler = new EntityAccessHandler([$policy]);
        $filter = new McpEntityFieldFilter($handler);

        $entity = $this->makeEntity('node', ['title' => 'T', 'body' => 'B', 'secret' => 'S']);
        $serialized = [
            'type' => 'node',
            'id' => '1',
            'attributes' => ['title' => 'T', 'body' => 'B', 'secret' => 'S'],
        ];

        $result = $filter->applyTo($serialized, $entity, $this->anonymousAccount());

        self::assertSame('T', $result['attributes']['title']);
        self::assertSame(McpEntityFieldFilter::REDACTION_MARKER, $result['attributes']['body']);
        self::assertSame(McpEntityFieldFilter::REDACTION_MARKER, $result['attributes']['secret']);
    }

    #[Test]
    public function noAttributesKeyReturnedUnchanged(): void
    {
        $handler = new EntityAccessHandler([]);
        $filter = new McpEntityFieldFilter($handler);

        $entity = $this->makeEntity('node', []);
        $serialized = ['type' => 'node', 'id' => '1'];

        $result = $filter->applyTo($serialized, $entity, $this->anonymousAccount());

        self::assertSame($serialized, $result);
    }

    #[Test]
    public function emptyAttributesReturnedUnchanged(): void
    {
        $handler = new EntityAccessHandler([]);
        $filter = new McpEntityFieldFilter($handler);

        $entity = $this->makeEntity('node', []);
        $serialized = ['type' => 'node', 'id' => '1', 'attributes' => []];

        $result = $filter->applyTo($serialized, $entity, $this->anonymousAccount());

        self::assertSame([], $result['attributes']);
    }

    #[Test]
    public function redactionMarkerShapeMatchesCanonicalContract(): void
    {
        // C-003: marker shape uniqueness — verify the exact structure.
        self::assertSame(
            ['accessRestricted' => true, 'reason' => 'field_forbidden_for_account'],
            McpEntityFieldFilter::REDACTION_MARKER,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function policyForbiddingField(string $forbiddenField): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class($forbiddenField) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly string $forbiddenField) {}

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
                if ($fieldName === $this->forbiddenField && $operation === 'view') {
                    return AccessResult::forbidden('Field is restricted.');
                }
                return AccessResult::neutral();
            }
        };
    }

    private function makeEntity(string $entityTypeId, array $values): EntityInterface
    {
        return new class($entityTypeId, $values) implements EntityInterface {
            private array $values;

            public function __construct(
                private readonly string $entityTypeId,
                array $values,
            ) {
                $this->values = $values;
            }

            public function id(): int|string|null { return $this->values['id'] ?? null; }
            public function uuid(): string { return $this->values['uuid'] ?? ''; }
            public function label(): string { return (string) ($this->values['title'] ?? ''); }
            public function bundle(): string { return $this->values['bundle'] ?? 'default'; }
            public function getEntityTypeId(): string { return $this->entityTypeId; }
            public function isNew(): bool { return false; }
            public function language(): string { return 'en'; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { $this->values[$name] = $value; return $this; }
            public function toArray(): array { return $this->values; }
        };
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
