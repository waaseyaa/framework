<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Transaction;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheTagsInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheSubscriber;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\EntityStorage\Tests\Fixtures\AttributeColumnEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Scheduler\Fence\DatabaseFenceGuard;

/** Real fence/repository/cache proof for #2734 on both SQL layouts. */
#[CoversNothing]
final class RepositoryOutermostCompletionTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, bool}> */
    public static function cases(): iterable
    {
        foreach ([TestStorageEntity::class, AttributeColumnEntity::class] as $class) {
            $layout = $class === TestStorageEntity::class ? 'sql-blob' : 'sql-column';
            foreach (['save', 'saveMany', 'delete', 'deleteMany'] as $operation) {
                foreach ([false, true] as $rollback) {
                    yield "{$layout}-{$operation}-" . ($rollback ? 'rollback' : 'commit') => [
                        $class,
                        $operation,
                        $rollback,
                    ];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function fencedRepositoryEffectsFollowTheOutermostOutcome(
        string $entityClass,
        string $operation,
        bool $rollback,
    ): void {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_effect_fences (
                resource_key VARCHAR(512) NOT NULL,
                fence_domain VARCHAR(255) NOT NULL,
                accepted_fence INTEGER NOT NULL,
                effect_id VARCHAR(255) NOT NULL,
                PRIMARY KEY (resource_key, fence_domain)
            )
            SQL);
        EntityMutationAuthoritySchema::ensure($database);

        $dispatcher = new SymfonyEventDispatcherAdapter();
        $fieldRegistry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: $dispatcher,
            fieldRegistry: $fieldRegistry,
            logger: new NullLogger(),
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn($definition) => null,
            accountContextAttacher: static function (object $repository): void {},
            fieldReadScope: new AccountFieldReadScope(),
            fieldTypes: $fieldRegistry->fieldTypeManager(),
        );
        $definition = EntityType::fromClass($entityClass);
        $manager->registerEntityType($definition);
        new EntitySchemaSyncRunner($database, $fieldRegistry)->run($manager->getDefinitions());
        $repository = $manager->getRepository($definition->id());
        self::assertInstanceOf(EntityRepository::class, $repository);

        $labelField = $entityClass === TestStorageEntity::class ? 'label' : 'title';
        $first = $repository->create(['id' => '1', 'uuid' => 'uuid-1', $labelField => 'before-1']);
        $second = $repository->create(['id' => '2', 'uuid' => 'uuid-2', $labelField => 'before-2']);
        self::assertInstanceOf(EntityBase::class, $first);
        self::assertInstanceOf(EntityBase::class, $second);
        $repository->saveMany([$first, $second], validate: false);
        $initialVersions = [
            $first->mutationToken()?->aggregateVersion,
            $second->mutationToken()?->aggregateVersion,
        ];

        $cache = new MemoryBackend();
        $tagInvalidator = new CacheTagsInvalidator();
        $tagInvalidator->registerBin('entity', $cache);
        EntityCacheSubscriber::register($dispatcher, new EntityCacheInvalidator($tagInvalidator));
        foreach (['1', '2'] as $id) {
            $cache->set("entity:{$id}", "before-{$id}", tags: [
                "entity:{$definition->id()}",
                "entity:{$definition->id()}:{$id}",
            ]);
        }

        $depths = [];
        $eventName = str_starts_with($operation, 'delete')
            ? EntityEvents::POST_DELETE->value
            : EntityEvents::POST_SAVE->value;
        $dispatcher->addListener($eventName, static function () use (&$depths, $database): void {
            $depths[] = $database->getConnection()->getTransactionNestingLevel();
        });

        $affected = str_ends_with($operation, 'Many') ? [$first, $second] : [$first];
        try {
            new DatabaseFenceGuard($database)->execute(
                "entity:{$definition->id()}:batch",
                'retention',
                1,
                "{$operation}-effect",
                function () use ($repository, $operation, $affected, $labelField, $rollback): void {
                    if ($operation === 'save' || $operation === 'saveMany') {
                        foreach ($affected as $index => $entity) {
                            $entity->set($labelField, 'after-' . ($index + 1));
                        }
                        if ($operation === 'save') {
                            $repository->save($affected[0], validate: false);
                        } else {
                            $repository->saveMany($affected, validate: false);
                        }
                    } elseif ($operation === 'delete') {
                        $repository->delete($affected[0]);
                    } else {
                        $repository->deleteMany($affected);
                    }

                    if ($rollback) {
                        throw new \RuntimeException('injected outer failure');
                    }
                },
            );
            self::assertFalse($rollback, 'The injected outer failure must escape the fence.');
        } catch (\RuntimeException $failure) {
            self::assertTrue($rollback);
            self::assertSame('injected outer failure', $failure->getMessage());
        }

        $affectedCount = count($affected);
        self::assertSame($rollback ? [] : array_fill(0, $affectedCount, 0), $depths);
        foreach (['1', '2'] as $index => $id) {
            $isAffected = $index < $affectedCount;
            $item = $cache->get("entity:{$id}");
            self::assertNotFalse($item);
            // EntityCacheInvalidator invalidates both the entity id tag and
            // the entity-type list tag, so every cached item of this type is
            // invalid after one committed mutation. A rollback invalidates none.
            self::assertSame($rollback, $item->valid);

            $reloaded = $repository->find($id);
            if ($isAffected && !$rollback && str_starts_with($operation, 'delete')) {
                self::assertNull($reloaded);
                continue;
            }
            self::assertNotNull($reloaded);
            $storedLabel = $database->getConnection()->fetchOne(sprintf(
                'SELECT %s FROM %s WHERE id = ?',
                $database->quoteIdentifier($labelField),
                $database->quoteIdentifier($definition->id()),
            ), [$id]);
            $expectedLabel = $isAffected && !$rollback && str_starts_with($operation, 'save')
                ? 'after-' . ($index + 1)
                : "before-{$id}";
            self::assertSame($expectedLabel, $storedLabel);
        }

        if ($rollback && str_starts_with($operation, 'save')) {
            self::assertSame($initialVersions[0], $first->mutationToken()?->aggregateVersion);
            if ($operation === 'saveMany') {
                self::assertSame($initialVersions[1], $second->mutationToken()?->aggregateVersion);
            }
        }
    }
}
