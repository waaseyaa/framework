<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Audit R2 WP1 — sink hardening: {@see \Waaseyaa\EntityStorage\SqlEntityQuery::resolveField()}
 * interpolates a field name RAW into a `json_extract(_data, '$.<field>')` SQL fragment for any
 * field not backed by a real column. Only the *value* is bound as a parameter; the field
 * *identifier* is not. A field name containing a single quote breaks out of the `'$.<field>'`
 * string literal.
 *
 * The primary fix is the JSON:API allowlist ({@see \Waaseyaa\Api\JsonApiController}), but this
 * is defense-in-depth at the actual interpolation sink: {@see \Waaseyaa\EntityStorage\SqlEntityQuery}
 * must refuse to build the expression at all when the field name is not a safe identifier
 * (`[A-Za-z0-9_]+`), regardless of which caller reached it.
 */
#[CoversClass(\Waaseyaa\EntityStorage\SqlEntityQuery::class)]
final class SqlEntityQueryJsonFieldNameGuardTest extends TestCase
{
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'person',
            label: 'Person',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $database);
        $schemaHandler->ensureTable();

        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver);
        $this->repository = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $database,
        );

        // 'mail' and 'some_data_field' are NOT table columns — they go into _data JSON,
        // which is the resolveField() branch that builds the raw json_extract() expression.
        $this->repository->save(
            $this->repository->create(['name' => 'Alice', 'mail' => 'alice@example.com', 'some_data_field' => 'ok']),
            validate: false,
        );
    }

    #[Test]
    public function maliciousConditionFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->getQuery()
            ->accessCheck(false)
            ->condition("evil') UNION SELECT--", 'x')
            ->execute();
    }

    #[Test]
    public function maliciousSortFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->getQuery()
            ->accessCheck(false)
            ->sort("evil') UNION SELECT--")
            ->execute();
    }

    #[Test]
    public function fieldNameWithSingleQuoteThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->getQuery()
            ->accessCheck(false)
            ->condition("mail' OR '1'='1", 'x')
            ->execute();
    }

    #[Test]
    public function validDataFieldNameStillResolvesFine(): void
    {
        // Positive control: a legitimate _data field name (letters, digits, underscores only)
        // must NOT be rejected by the guard — it still resolves to the json_extract() path and
        // executes without throwing.
        $ids = $this->repository->getQuery()
            ->accessCheck(false)
            ->condition('some_data_field', 'ok')
            ->execute();

        $this->assertCount(1, $ids);
    }

    // --- Twin sink: EntityRepository::findBy()/count() -> SqlStorageDriver::resolveField() ---
    //
    // SqlStorageDriver has the EXACT same raw json_extract() interpolation as SqlEntityQuery,
    // reached by a DIFFERENT call path (findBy/count, not getQuery()->execute()) that neither
    // the JSON:API allowlist nor the SqlEntityQuery guard covers. It is live today via
    // ai-tools EntityListTool (an #[AsAgentTool] whose filter/sort schema is open). Both sinks
    // must be closed for the "un-injectable regardless of caller" claim to hold.

    #[Test]
    public function maliciousFindByCriteriaFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findBy(["evil') UNION SELECT--" => 'x']);
    }

    #[Test]
    public function maliciousFindByOrderByFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->findBy([], ["evil') UNION SELECT--" => 'ASC']);
    }

    #[Test]
    public function maliciousCountCriteriaFieldNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->count(["evil') UNION SELECT--" => 'x']);
    }

    #[Test]
    public function validFindByDataFieldNameStillResolvesFine(): void
    {
        // Positive control on the twin path: a legitimate _data field name still resolves to
        // the json_extract() expression and returns the matching row through findBy().
        $entities = $this->repository->findBy(['some_data_field' => 'ok']);

        $this->assertCount(1, $entities);
    }
}
