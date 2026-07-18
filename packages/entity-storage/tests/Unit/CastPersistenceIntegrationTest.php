<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Tests\Fixtures\CastPersistenceLeafVo;
use Waaseyaa\EntityStorage\Tests\Fixtures\CastPersistenceOuterVo;
use Waaseyaa\EntityStorage\Tests\Fixtures\CastPersistenceStringEnum;
use Waaseyaa\EntityStorage\Tests\Fixtures\CastPersistenceTestEntity;

/**
 * Verifies #1181 persistence invariant: hydrate/load keeps storage-shaped $values;
 * get() applies casts; toArray() / driver rows stay JSON-safe after set() (castOut).
 */
#[CoversClass(EntityRepository::class)]
final class CastPersistenceIntegrationTest extends TestCase
{
    private const ENTITY_KEYS = [
        'id' => 'id',
        'uuid' => 'uuid',
        'bundle' => 'bundle',
        'label' => 'label',
        'langcode' => 'langcode',
    ];

    /**
     * @return array{0: EntityType, 1: InMemoryStorageDriver, 2: EntityRepository}
     */
    private function createRepositoryFixture(): array
    {
        $driver = new InMemoryStorageDriver();
        $entityType = new EntityType(
            id: 'cast_persist_entity',
            label: 'Cast Persist',
            class: CastPersistenceTestEntity::class,
            keys: self::ENTITY_KEYS,
        );
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            new EventDispatcher(),
        );

        return [$entityType, $driver, $repository];
    }

    /**
     * @param InMemoryStorageDriver $driver
     * @param array<string, mixed> $patch
     */
    private function patchInMemoryRow(
        InMemoryStorageDriver $driver,
        string $entityTypeId,
        string $id,
        array $patch,
    ): void {
        $ref = new ReflectionClass($driver);
        $prop = $ref->getProperty('store');
        /** @var array<string, array<string, array<string, mixed>>> $store */
        $store = $prop->getValue($driver);
        foreach ($patch as $key => $value) {
            $store[$entityTypeId][$id][$key] = $value;
        }
        $prop->setValue($driver, $store);
    }

    #[Test]
    public function repository_round_trip_get_casts_after_find(): void
    {
        [, $driver, $repository] = $this->createRepositoryFixture();

        $entity = new CastPersistenceTestEntity(
            values: [
                'id' => 1,
                'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'label' => 'One',
                'bundle' => 'article',
                'langcode' => 'en',
            ],
            entityKeys: self::ENTITY_KEYS,
        );
        $entity->enforceIsNew(true);
        $entity->set('score', 42);
        $entity->set('tags', ['k' => 'v']);
        $entity->set('mode', CastPersistenceStringEnum::On);

        $repository->save($entity);

        $found = $repository->find('1');
        self::assertInstanceOf(CastPersistenceTestEntity::class, $found);
        self::assertSame(42, $found->get('score'));
        self::assertSame(['k' => 'v'], $found->get('tags'));
        self::assertSame(CastPersistenceStringEnum::On, $found->get('mode'));

        $row = $this->readInMemoryRow($driver, 'cast_persist_entity', '1');
        self::assertSame(42, $row['score']);
        self::assertSame('{"k":"v"}', $row['tags']);
        self::assertSame('on', $row['mode']);
    }

    #[Test]
    public function repository_round_trip_nested_value_object(): void
    {
        [, $driver, $repository] = $this->createRepositoryFixture();

        $entity = new CastPersistenceTestEntity(
            values: [
                'id' => 10,
                'uuid' => '10101010-1010-4101-8101-101010101010',
                'label' => 'Ten',
                'bundle' => 'article',
                'langcode' => 'en',
            ],
            entityKeys: self::ENTITY_KEYS,
        );
        $entity->enforceIsNew(true);
        $entity->set(
            'nested_profile',
            new CastPersistenceOuterVo(leaf: new CastPersistenceLeafVo(code: 'persisted')),
        );
        $repository->save($entity);

        $found = $repository->find('10');
        self::assertInstanceOf(CastPersistenceTestEntity::class, $found);
        $profile = $found->get('nested_profile');
        self::assertInstanceOf(CastPersistenceOuterVo::class, $profile);
        self::assertSame('persisted', $profile->leaf->code);

        $row = $this->readInMemoryRow($driver, 'cast_persist_entity', '10');
        self::assertSame('{"leaf":{"code":"persisted"}}', $row['nested_profile']);
    }

    #[Test]
    public function repository_find_casts_numeric_string_from_driver_like_sqlite(): void
    {
        [, $driver, $repository] = $this->createRepositoryFixture();

        $entity = new CastPersistenceTestEntity(
            values: [
                'id' => 2,
                'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'label' => 'Two',
                'bundle' => 'article',
                'langcode' => 'en',
            ],
            entityKeys: self::ENTITY_KEYS,
        );
        $entity->enforceIsNew(true);
        $entity->set('score', 1);
        $repository->save($entity);

        $this->patchInMemoryRow($driver, 'cast_persist_entity', '2', ['score' => '99']);

        $found = $repository->find('2');
        self::assertNotNull($found);
        self::assertSame(99, $found->get('score'));
    }

    #[Test]
    public function to_array_after_set_enum_contains_backing_scalar_only(): void
    {
        $entity = new CastPersistenceTestEntity(
            values: [
                'id' => 3,
                'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'label' => 'Three',
                'bundle' => 'article',
                'langcode' => 'en',
            ],
            entityKeys: self::ENTITY_KEYS,
        );
        $entity->set('mode', CastPersistenceStringEnum::Off);

        $raw = $entity->toArray();
        self::assertSame('off', $raw['mode']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readInMemoryRow(
        InMemoryStorageDriver $driver,
        string $entityTypeId,
        string $id,
    ): array {
        $ref = new ReflectionClass($driver);
        $prop = $ref->getProperty('store');
        /** @var array<string, array<string, array<string, mixed>>> $store */
        $store = $prop->getValue($driver);

        return $store[$entityTypeId][$id];
    }
}
