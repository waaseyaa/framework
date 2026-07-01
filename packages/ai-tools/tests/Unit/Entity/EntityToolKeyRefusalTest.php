<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Entity\EntityCreateTool;
use Waaseyaa\AI\Tools\Entity\EntityKeyGuard;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;

/**
 * WP03 / T013: identity-key refusal short-circuits and structured
 * validation-error mapping on the stock create/update tools, per
 * contracts/tool-refusal.md (mission
 * live-entity-validation-key-protection-01KTWQT3).
 */
#[CoversClass(EntityCreateTool::class)]
#[CoversClass(EntityUpdateTool::class)]
#[CoversClass(EntityKeyGuard::class)]
final class EntityToolKeyRefusalTest extends TestCase
{
    private InMemoryToolRepository $repo;
    private SingleTypeEntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->repo = new InMemoryToolRepository();
        $this->repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original', 'langcode' => 'en']));
        $type = new EntityType(
            id: 'tool_test',
            label: 'Tool Test',
            class: CountingToolEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'revision_id',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            revisionDefault: true,
        );
        $this->etm = new SingleTypeEntityTypeManager($type, $this->repo);
        CountingToolEntity::$constructed = 0;
    }

    /** @param list<string> $permissions */
    private function account(array $permissions): AccountInterface
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

    #[Test]
    public function refused_update_never_saves_and_never_mutates_the_loaded_entity(): void
    {
        $tool = new EntityUpdateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['langcode' => 'xx', 'title' => 'Hijacked']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame([], $this->repo->saved, 'save() was never called');
        $entity = $this->repo->find('1');
        $this->assertNotNull($entity);
        $this->assertSame('Original', $entity->get('title'), 'whole-write rejection: not even the content key was set');
        $this->assertSame('en', $entity->get('langcode'));
    }

    #[Test]
    public function refused_create_never_instantiates_the_entity_class(): void
    {
        $tool = new EntityCreateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['id' => '9', 'title' => 'Made']],
            $this->account(['tool.entity.create']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(0, CountingToolEntity::$constructed, 'entity class was never instantiated');
        $this->assertSame([], $this->repo->saved);
    }

    #[Test]
    public function every_registered_identity_key_kind_is_refused_on_update(): void
    {
        $tool = new EntityUpdateTool($this->etm);

        foreach (['id', 'uuid', 'revision_id', 'langcode', 'default_langcode'] as $column) {
            $result = $tool->execute(
                ['entity_type' => 'tool_test', 'id' => '1', 'values' => [$column => 'x']],
                $this->account(['tool.entity.update']),
            );

            $this->assertTrue($result->isError, sprintf('update must refuse "%s"', $column));
            $this->assertSame(
                sprintf('entity.update: refused identity keys: %s — identity fields cannot be written through this tool', $column),
                $result->content[0]['text'] ?? null,
            );
        }
        $this->assertSame([], $this->repo->saved);
    }

    #[Test]
    public function every_registered_identity_key_kind_is_refused_on_create(): void
    {
        $tool = new EntityCreateTool($this->etm);

        foreach (['id', 'uuid', 'revision_id', 'langcode', 'default_langcode'] as $column) {
            $result = $tool->execute(
                ['entity_type' => 'tool_test', 'values' => [$column => 'x']],
                $this->account(['tool.entity.create']),
            );

            $this->assertTrue($result->isError, sprintf('create must refuse "%s"', $column));
            $this->assertSame(
                sprintf('entity.create: refused identity keys: %s — identity fields cannot be written through this tool', $column),
                $result->content[0]['text'] ?? null,
            );
        }
        $this->assertSame(0, CountingToolEntity::$constructed);
        $this->assertSame([], $this->repo->saved);
    }

    #[Test]
    public function multiple_refused_keys_are_named_in_one_error_sorted(): void
    {
        $tool = new EntityUpdateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['uuid' => 'abc', 'langcode' => 'xx', 'id' => '2']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.update: refused identity keys: id, langcode, uuid — identity fields cannot be written through this tool',
            $result->content[0]['text'] ?? null,
        );
        $this->assertSame(
            ['error' => 'identity_keys_refused', 'refused_keys' => ['id', 'langcode', 'uuid']],
            $result->content[1]['data'] ?? null,
        );
    }

    #[Test]
    public function the_id_locator_argument_is_not_refused_on_update(): void
    {
        $tool = new EntityUpdateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'Renamed']],
            $this->account(['tool.entity.update']),
        );

        $this->assertFalse($result->isError, 'the locator id is outside the values payload and never refused');
        $this->assertCount(1, $this->repo->saved);
    }

    #[Test]
    public function dry_run_reports_the_refusal_identically_on_both_tools(): void
    {
        $create = new EntityCreateTool($this->etm);
        $update = new EntityUpdateTool($this->etm);

        $createResult = $create->dryRun(
            ['entity_type' => 'tool_test', 'values' => ['uuid' => 'abc']],
            $this->account(['tool.entity.create']),
        );
        $updateResult = $update->dryRun(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['langcode' => 'xx']],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($createResult->isError);
        $this->assertSame(
            'entity.create: refused identity keys: uuid — identity fields cannot be written through this tool',
            $createResult->content[0]['text'] ?? null,
        );
        $this->assertTrue($updateResult->isError);
        $this->assertSame(
            'entity.update: refused identity keys: langcode — identity fields cannot be written through this tool',
            $updateResult->content[0]['text'] ?? null,
        );
        $this->assertSame([], $this->repo->saved);
        $this->assertSame(0, CountingToolEntity::$constructed);
    }

    #[Test]
    public function revision_log_still_works_on_a_clean_create_payload(): void
    {
        $tool = new EntityCreateTool($this->etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['title' => 'Made'], 'revision_log' => 'agent created'],
            $this->account(['tool.entity.create']),
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $this->repo->saved);
        $saved = $this->repo->saved[0];
        $this->assertInstanceOf(CountingToolEntity::class, $saved);
        $this->assertSame('agent created', $saved->getRevisionLog());
    }

    #[Test]
    public function update_validation_failure_maps_to_a_sorted_structured_error(): void
    {
        // Violations deliberately out of alphabetical order.
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value is too long.', null, [], null, 'title', 'zzz'),
            new ConstraintViolation('This value should be between 0 and 100.', null, [], null, 'score', 200),
        ]);
        $repo = new ValidationFailingRepository(new EntityValidationException($violations));
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original']));
        $etm = new SingleTypeEntityTypeManager($this->etm->getDefinition('tool_test'), $repo);
        $tool = new EntityUpdateTool($etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'id' => '1', 'values' => ['title' => 'zzz', 'score' => 200]],
            $this->account(['tool.entity.update']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.update: validation failed: score: This value should be between 0 and 100.; title: This value is too long.',
            $result->content[0]['text'] ?? null,
        );
        $this->assertSame(
            [
                'error' => 'validation_failed',
                'violations' => [
                    ['field' => 'score', 'message' => 'This value should be between 0 and 100.', 'invalid_value_type' => 'int'],
                    ['field' => 'title', 'message' => 'This value is too long.', 'invalid_value_type' => 'string'],
                ],
            ],
            $result->content[1]['data'] ?? null,
        );
    }

    #[Test]
    public function create_validation_failure_maps_to_a_sorted_structured_error(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value is too long.', null, [], null, 'title', 'zzz'),
            new ConstraintViolation('This value should not be null.', null, [], null, 'body', null),
        ]);
        $repo = new ValidationFailingRepository(new EntityValidationException($violations));
        $etm = new SingleTypeEntityTypeManager($this->etm->getDefinition('tool_test'), $repo);
        $tool = new EntityCreateTool($etm);

        $result = $tool->execute(
            ['entity_type' => 'tool_test', 'values' => ['title' => 'zzz']],
            $this->account(['tool.entity.create']),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.create: validation failed: body: This value should not be null.; title: This value is too long.',
            $result->content[0]['text'] ?? null,
        );
        $this->assertSame(
            [
                'error' => 'validation_failed',
                'violations' => [
                    ['field' => 'body', 'message' => 'This value should not be null.', 'invalid_value_type' => 'null'],
                    ['field' => 'title', 'message' => 'This value is too long.', 'invalid_value_type' => 'string'],
                ],
            ],
            $result->content[1]['data'] ?? null,
        );
    }
}

/**
 * Counts constructor invocations so the create-tool refusal tests can assert
 * the entity class is never instantiated on a refused payload.
 *
 * @internal
 */
final class CountingToolEntity implements EntityInterface
{
    public static int $constructed = 0;

    private string $revisionLog = '';

    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
        ++self::$constructed;
    }

    public function enforceIsNew(): void {}

    public function id(): int|string|null
    {
        return $this->values['id'] ?? null;
    }

    public function uuid(): string
    {
        return (string) ($this->values['uuid'] ?? '');
    }

    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return 'tool_test';
    }

    public function bundle(): string
    {
        return '';
    }

    public function isNew(): bool
    {
        return true;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }

    public function setRevisionLog(string $log): static
    {
        $this->revisionLog = $log;

        return $this;
    }

    public function getRevisionLog(): string
    {
        return $this->revisionLog;
    }
}

/**
 * Repository whose save() throws the supplied EntityValidationException, for
 * exercising the tools' validation-error mapping.
 *
 * @internal
 */
final class ValidationFailingRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $store = [];

    public function __construct(private readonly EntityValidationException $exception) {}

    public function seed(EntityInterface $entity): void
    {
        $this->store[(string) $entity->id()] = $entity;
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \LogicException('ValidationFailingRepository does not support create().');
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->store[$id] ?? null;
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        throw $this->exception;
    }

    public function delete(EntityInterface $entity): void {}

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return [];
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        return array_values($this->store);
    }

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        throw new \LogicException('getQuery() not implemented in this test double');
    }

    public function exists(string $id): bool
    {
        return isset($this->store[$id]);
    }

    public function count(array $criteria = []): int
    {
        return count($this->store);
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        return null;
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        return null;
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('not used by these tests');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('not used by these tests');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \BadMethodCallException('not used by these tests');
    }

    public function listRevisions(string $entityId): array
    {
        return [];
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return [];
    }

    public function deleteMany(array $entities): int
    {
        return 0;
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('not used by these tests');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('not used by these tests');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('not used by these tests');
    }
}
