<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\AI\Tools\Entity\EntitySetCurrentRevisionTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueComparator;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * R4 PR1 WP2 (security, audit-remediation batch 2026-07-03): entity.set_current_revision
 * re-points the base table at an arbitrary historical revision's WHOLE row via
 * EntityRepository::setCurrentRevision(), so it can silently re-apply a
 * privileged field the calling account could never set through entity.update
 * directly (e.g. a `roles` field gated by 'administer users'). The tool must
 * gate the fields the restore would CHANGE the same way entity.update gates
 * its values payload.
 */
#[CoversClass(EntitySetCurrentRevisionTool::class)]
final class EntitySetCurrentRevisionToolFieldAccessTest extends TestCase
{
    private const array KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

    private DBALDatabase $db;
    private EntityType $entityType;
    private EntityRepository $repo;
    private EntitySetCurrentRevisionTool $tool;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $this->entityType),
            $this->db,
        );

        $this->tool = new EntitySetCurrentRevisionTool(new SingleTypeEntityTypeManager($this->entityType, $this->repo));
        $this->tool->setAccessHandler($this->accessHandler());
    }

    /** Entity-level update always allowed; field 'roles' edit requires 'administer users'. */
    private function accessHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'test_revisionable';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'update' ? AccessResult::allowed() : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                // Credential field: unconditional Forbidden for EVERYONE (mirrors
                // UserAccessPolicy::CREDENTIAL_FIELDS — no 'administer users' bypass).
                if ($fieldName === 'pass') {
                    return AccessResult::forbidden('pass is not editable through the generic field surface');
                }
                if ($operation === 'edit' && $fieldName === 'roles' && !$account->hasPermission('administer users')) {
                    return AccessResult::forbidden('roles requires administer users');
                }

                return AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    private function nonAdmin(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'tool.entity.update';
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

    private function admin(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['administrator'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /** Seed revision 1 with roles=['administrator'], then move the head to revision 2 with roles=['viewer']. */
    private function seedPrivilegedHistory(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a', 'roles' => ['administrator']]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $current = $this->repo->find('1');
        \assert($current instanceof TestRevisionableEntity);
        $current->set('title', 'v2');
        $current->set('roles', ['viewer']);
        $this->repo->save($current);
    }

    #[Test]
    public function non_admin_cannot_set_current_to_a_revision_that_would_re_grant_privileged_roles(): void
    {
        $this->seedPrivilegedHistory();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'revision_id' => 1],
            $this->nonAdmin(),
        );

        self::assertTrue($result->isError, 'pointing current at a privileged-field change must be refused');
        self::assertSame('forbidden', $result->summary);

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertRestrictedFieldsMatch($reloaded, ['roles' => ['viewer']], 'no write happened — roles must remain unchanged');
        self::assertSame(2, $reloaded->getRevisionId(), 'the base-table pointer must not have moved');
    }

    #[Test]
    public function admin_can_set_current_to_a_revision_that_changes_privileged_roles(): void
    {
        $this->seedPrivilegedHistory();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'revision_id' => 1],
            $this->admin(),
        );

        self::assertFalse($result->isError, $result->summary ?? '');
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertRestrictedFieldsMatch($reloaded, ['roles' => ['administrator']]);
        self::assertSame(1, $reloaded->getRevisionId());
    }

    #[Test]
    public function admin_can_set_current_across_a_credential_field_rotation(): void
    {
        // Availability regression: a credential field (`pass`) is unconditionally
        // edit-Forbidden for EVERYONE (no admin bypass), but it rides every
        // revision snapshot. Pointing current across a password rotation must NOT
        // be blocked on the credential difference.
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a', 'roles' => ['viewer'], 'pass' => 'hash-v1']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $current = $this->repo->find('1');
        \assert($current instanceof TestRevisionableEntity);
        $current->set('title', 'v2');
        $current->set('pass', 'hash-v2'); // password rotated between revisions
        $this->repo->save($current);

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'revision_id' => 1],
            $this->admin(),
        );

        self::assertFalse($result->isError, 'a credential-only difference must not block the pointer move: ' . ($result->summary ?? ''));
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        self::assertSame('v1', $reloaded->label());
        $this->assertRestrictedFieldsMatch($reloaded, ['pass' => 'hash-v1'], 'the whole-row restore still writes the credential field');
    }

    #[Test]
    public function non_admin_can_set_current_to_a_revision_that_only_changes_non_privileged_fields(): void
    {
        // Both revisions carry the same roles — the pointer move only changes `title`.
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a', 'roles' => ['viewer']]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $current = $this->repo->find('1');
        \assert($current instanceof TestRevisionableEntity);
        $current->set('title', 'v2');
        $this->repo->save($current);

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'revision_id' => 1],
            $this->nonAdmin(),
        );

        self::assertFalse($result->isError, $result->summary ?? '');
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        self::assertSame('v1', $reloaded->label());
        $this->assertRestrictedFieldsMatch($reloaded, ['roles' => ['viewer']]);
    }

    /** @param array<string, mixed> $expected */
    private function assertRestrictedFieldsMatch(TestRevisionableEntity $actual, array $expected, string $message = ''): void
    {
        $expectedEntity = new TestRevisionableEntity(values: $expected);
        self::assertSame([], new EntityValueComparator()->changedFieldNames($actual, $expectedEntity, array_keys($expected)), $message);
    }
}
