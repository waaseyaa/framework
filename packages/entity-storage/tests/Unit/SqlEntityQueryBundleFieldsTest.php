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
use Waaseyaa\EntityStorage\Exception\BundleAmbiguousFieldException;
use Waaseyaa\EntityStorage\Exception\UnknownFieldException;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * Commit 5 — SqlEntityQuery condition/sort routing across bundle subtables.
 *
 * Exercises the ambiguity policy and INNER JOIN injection contract defined in
 * docs/specs/bundle-scoped-fields.md §Query.
 */
#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryBundleFieldsTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $groupType;
    private FieldDefinitionRegistry $registry;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $this->groupType = new EntityType(
            id: 'group',
            label: 'Group',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'gid',
                'uuid' => 'uuid',
                'bundle' => 'type',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
            bundleEntityType: 'group_type',
        );

        $this->registry = new FieldDefinitionRegistry();
        $this->dispatcher = new EventDispatcher();
    }

    #[Test]
    public function conditionOnCoreFieldDoesNotRequireAnyBundleJoin(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        $ids = $query->condition('label', 'Acme')->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function conditionOnBundleFieldInjectsInnerJoinAndNarrowsToBundle(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        $ids = $query->condition('phone', '555-0100')->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function multipleConditionsOnSameBundleDeduplicateToSingleJoin(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        // Two conditions on 'business' bundle fields must not produce duplicate
        // JOIN aliases — DBAL would throw on a repeated alias.
        $ids = $query
            ->condition('phone', '555-0100')
            ->condition('email', 'hi@acme.example')
            ->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function conditionsAcrossDifferentBundlesInjectBothJoins(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        // 'phone' only in business, 'website' only in organization — each is
        // individually unambiguous, so routing succeeds. No single entity can
        // be in both bundles, so the intersection via INNER JOINs is empty.
        $ids = $query
            ->condition('phone', '555-0100')
            ->condition('website', 'https://openorg.example')
            ->execute();

        self::assertSame([], $ids);
    }

    #[Test]
    public function orderByOnBundleFieldInjectsJoinAndSortsResults(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seedMultipleBusinesses();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        $ids = $query
            ->condition('type', 'business')
            ->sort('phone', 'ASC')
            ->execute();

        // Three business entities ordered by phone ascending.
        self::assertCount(3, $ids);
        self::assertSame(
            ['555-0100', '555-0200', '555-0300'],
            $this->phonesForIds($ids),
        );
    }

    #[Test]
    public function unknownFieldThrowsUnknownFieldException(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);

        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('not_a_field');

        $query->condition('not_a_field', 'anything')->execute();
    }

    #[Test]
    public function ambiguousBundleFieldWithoutConstraintThrows(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);

        $this->expectException(BundleAmbiguousFieldException::class);
        $this->expectExceptionMessage('Field "email" is bundle-scoped');
        $this->expectExceptionMessage("bundles [business, organization]");
        $this->expectExceptionMessage("->condition('type', '<bundle>')");

        $query->condition('email', 'anything')->execute();
    }

    #[Test]
    public function ambiguousBundleFieldWithExplicitBundleConditionResolves(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        $ids = $query
            ->condition('type', 'business')
            ->condition('email', 'hi@acme.example')
            ->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function bundleScopedFieldUniqueToOneBundleIsUnambiguous(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        // 'website' only exists in organization — no ambiguity even without
        // an explicit bundle condition.
        $ids = $query->condition('website', 'https://openorg.example')->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function ambiguousFieldNarrowedBySiblingBundleScopedConditionResolves(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $this->seed();

        $query = new SqlEntityQuery(
            $this->groupType,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        // 'phone' is business-only; it uniquely identifies the bundle, so the
        // otherwise-ambiguous 'email' reference must resolve to 'business'.
        $ids = $query
            ->condition('phone', '555-0100')
            ->condition('email', 'hi@acme.example')
            ->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function entityTypeWithoutRegisteredFieldsBypassesRouting(): void
    {
        // No registrations against 'thing'. SqlEntityQuery must behave exactly
        // as it did before commit 5 for legacy single-bundle types.
        $singleBundle = new EntityType(
            id: 'thing',
            label: 'Thing',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
        (new SqlSchemaHandler($singleBundle, $this->database))->ensureTable();

        $resolver = new SingleConnectionResolver($this->database);
        $repository = new EntityRepository(
            $singleBundle,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );
        $entity = $repository->create([
            'uuid' => 'uuid-thing',
            'label' => 'Solo',
            'langcode' => 'en',
        ]);
        $repository->save($entity, validate: false);

        $query = new SqlEntityQuery(
            $singleBundle,
            $this->database,
            null,
            $this->registry,
        );
        $query->accessCheck(false);
        $ids = $query->condition('label', 'Solo')->execute();

        self::assertCount(1, $ids);
    }

    private function registerBusinessFields(): void
    {
        $this->registry->registerBundleFields('group', 'business', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
            new FieldDefinition(
                name: 'phone',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
        ]);
    }

    private function registerOrganizationFields(): void
    {
        $this->registry->registerBundleFields('group', 'organization', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
            new FieldDefinition(
                name: 'website',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
            new FieldDefinition(
                name: 'org_code',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
        ]);
    }

    /**
     * @param list<string> $bundles
     */
    private function ensureSchema(array $bundles): void
    {
        (new SqlSchemaHandler(
            $this->groupType,
            $this->database,
            $this->registry,
            static fn (): iterable => $bundles,
        ))->ensureTable();
    }

    private function makeRepository(): EntityRepository
    {
        $resolver = new SingleConnectionResolver($this->database);

        return new EntityRepository(
            $this->groupType,
            new SqlStorageDriver($resolver, 'gid'),
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );
    }

    private function seed(): void
    {
        $repository = $this->makeRepository();

        $biz = $repository->create([
            'uuid' => 'uuid-biz',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'phone' => '555-0100',
        ]);
        $repository->save($biz, validate: false);

        $org = $repository->create([
            'uuid' => 'uuid-org',
            'type' => 'organization',
            'label' => 'OpenOrg',
            'langcode' => 'en',
            'email' => 'hello@openorg.example',
            'website' => 'https://openorg.example',
            'org_code' => 'OPEN-1',
        ]);
        $repository->save($org, validate: false);
    }

    private function seedMultipleBusinesses(): void
    {
        $repository = $this->makeRepository();

        foreach ([
            ['uuid-b1', 'Acme',   'hi@acme.example',   '555-0100'],
            ['uuid-b2', 'Beagle', 'hi@beagle.example', '555-0300'],
            ['uuid-b3', 'Cogent', 'hi@cogent.example', '555-0200'],
        ] as [$uuid, $label, $email, $phone]) {
            $entity = $repository->create([
                'uuid' => $uuid,
                'type' => 'business',
                'label' => $label,
                'langcode' => 'en',
                'email' => $email,
                'phone' => $phone,
            ]);
            $repository->save($entity, validate: false);
        }
    }

    /**
     * @param list<int|string> $ids
     * @return list<string>
     */
    private function phonesForIds(array $ids): array
    {
        $phones = [];
        foreach ($ids as $id) {
            $row = null;
            foreach ($this->database->select('group__business')
                ->fields('group__business', ['phone'])
                ->condition('gid', (int) $id)
                ->execute() as $r) {
                $row = $r;
                break;
            }
            $phones[] = (string) ($row['phone'] ?? '');
        }

        return $phones;
    }
}
