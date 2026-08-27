<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\BundleUniqueKeyConflictException;
use Waaseyaa\EntityStorage\Exception\BundleUniqueKeyMigrationException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldStorage;

#[CoversClass(EntityRepository::class)]
#[CoversClass(BundleSubtableGateway::class)]
#[CoversClass(BundleUniqueKeyConflictException::class)]
#[CoversClass(BundleUniqueKeyMigrationException::class)]
#[CoversClass(SqlSchemaHandler::class)]
#[CoversClass(FieldDefinition::class)]
final class BundleScopedUniqueKeyTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private FieldDefinitionRegistry $registry;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'media',
            label: 'Media',
            class: TestStorageEntity::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label'],
            bundleEntityType: 'media_type',
        );
        $this->registry = new FieldDefinitionRegistry();
        $this->registerBundle('members_document');
        $this->registerBundle('other_document');
    }

    #[Test]
    public function dataBackedBundleKeyIsMaterializedAndEnforcedByTheRepository(): void
    {
        $this->registerMeetingDateKey();
        $handler = $this->handler();
        $handler->ensureTable();
        $handler->assertRuntimeSchema();

        self::assertTrue($this->database->schema()->fieldExists('media__members_document', 'meeting_date'));
        $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes('media__members_document');
        self::assertTrue($indexes['media_members_document_meeting_date']->isUnique());

        $repository = $this->repository();
        $first = $this->entity('first', 'members_document', '2026-08-01');
        $repository->save($first, validate: false);

        // A self-update keeps the same key value and must succeed.
        $first->set('label', 'First updated');
        $repository->save($first, validate: false);

        // The same value in another bundle is outside this key's scope.
        $repository->save($this->entity('other', 'other_document', '2026-08-01'), validate: false);

        try {
            $repository->save($this->entity('duplicate', 'members_document', '2026-08-01'), validate: false);
            self::fail('Expected bundle-scoped unique-key conflict.');
        } catch (BundleUniqueKeyConflictException $exception) {
            self::assertSame('BUNDLE_UNIQUE_KEY_CONFLICT', $exception->errorCode);
            self::assertSame('media', $exception->entityTypeId);
            self::assertSame('members_document', $exception->bundle);
            self::assertSame('media_members_document_meeting_date', $exception->keyName);
            self::assertSame(['meeting_date'], $exception->fields);
            self::assertSame(['meeting_date' => '2026-08-01'], $exception->values);
        }
    }

    #[Test]
    public function schemaSyncRefusesExistingDuplicatesBeforeAddingTheIndex(): void
    {
        $this->handler()->ensureTable();
        $repository = $this->repository();
        $repository->save($this->entity('first', 'members_document', '2026-08-02'), validate: false);
        $repository->save($this->entity('second', 'members_document', '2026-08-02'), validate: false);

        $this->registerMeetingDateKey();

        try {
            $this->handler()->ensureTable();
            self::fail('Expected duplicate preflight refusal.');
        } catch (BundleUniqueKeyMigrationException $exception) {
            self::assertSame('bundle_unique_key_duplicates', $exception->errorCode);
            self::assertSame('media_members_document_meeting_date', $exception->keyName);
        }

        $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes('media__members_document');
        self::assertArrayNotHasKey('media_members_document_meeting_date', $indexes);
    }

    #[Test]
    public function runtimeReadinessRefusesAMissingDeclaredIndexWithoutMutation(): void
    {
        $this->registerMeetingDateKey();
        $handler = $this->handler();
        $handler->ensureTable();
        $this->database->getConnection()->executeStatement(
            'DROP INDEX "media_members_document_meeting_date"',
        );
        $this->database->getConnection()->executeStatement(
            'CREATE INDEX "media_members_document_meeting_date" ON "media__members_document" ("meeting_date")',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[S1-DB106]');
        $this->expectExceptionMessage('media_members_document_meeting_date');
        $handler->assertRuntimeSchema();
    }

    #[Test]
    public function schemaSyncRefusesAnExistingIndexWithTheDeclaredNameButWrongShape(): void
    {
        $this->registerMeetingDateKey();
        $handler = $this->handler();
        $handler->ensureTable();
        $this->database->getConnection()->executeStatement('DROP INDEX "media_members_document_meeting_date"');
        $this->database->getConnection()->executeStatement(
            'CREATE INDEX "media_members_document_meeting_date" ON "media__members_document" ("meeting_date")',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match declared unique fields');
        $handler->ensureTable();
    }

    #[Test]
    public function malformedLegacyDataIsIgnoredDuringPromotedFieldBackfill(): void
    {
        $handler = $this->handler();
        $handler->ensureTable();
        $entity = $this->entity('legacy', 'members_document', '2026-08-04');
        $this->repository()->save($entity, validate: false);
        $this->database->update('media')
            ->fields(['_data' => '{invalid-json'])
            ->condition('mid', $entity->id())
            ->execute();
        $this->registerMeetingDateKey();

        $handler->ensureTable();

        $handler->assertRuntimeSchema();
        $indexes = $this->database->getConnection()->createSchemaManager()->listTableIndexes('media__members_document');
        self::assertTrue($indexes['media_members_document_meeting_date']->isUnique());
    }

    #[Test]
    public function nullDoesNotParticipateButEmptyStringDoes(): void
    {
        $this->registerMeetingDateKey();
        $this->handler()->ensureTable();
        $repository = $this->repository();

        $repository->save($this->entity('null-one', 'members_document', null), validate: false);
        $repository->save($this->entity('null-two', 'members_document', null), validate: false);
        $repository->save($this->entity('empty-one', 'members_document', ''), validate: false);

        $this->expectException(BundleUniqueKeyConflictException::class);
        $repository->save($this->entity('empty-two', 'members_document', ''), validate: false);
    }

    #[Test]
    public function saveRefusesWhenADeclaredUniqueBundleSubtableIsMissing(): void
    {
        $this->registerMeetingDateKey();
        $this->handler()->ensureTable();
        $this->database->getConnection()->executeStatement('DROP TABLE "media__members_document"');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('[S1-DB106]');
        $this->expectExceptionMessage('save was refused');
        $this->repository()->save($this->entity('missing', 'members_document', '2026-08-03'), validate: false);
    }

    #[Test]
    public function unboundedTextKeysAreRefusedBeforeTheirBundleTableIsCreated(): void
    {
        $this->registry->registerBundleFields('media', 'text_document', [
            new FieldDefinition(
                name: 'body',
                type: 'text_long',
                targetEntityTypeId: 'media',
                targetBundle: 'text_document',
                stored: FieldStorage::Data,
            ),
        ]);
        $this->registry->registerBundleUniqueKeys('media', 'text_document', [[
            'name' => 'media_text_document_body',
            'fields' => ['body'],
        ]]);

        try {
            $this->handler()->ensureTable();
            self::fail('Expected unmaterializable bundle key refusal.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('portable B-tree column', $exception->getMessage());
        }
        self::assertFalse($this->database->schema()->tableExists('media__text_document'));
    }

    private function registerBundle(string $bundle): void
    {
        $this->registry->registerBundleFields('media', $bundle, [
            new FieldDefinition(
                name: 'meeting_date',
                type: 'date',
                targetEntityTypeId: 'media',
                targetBundle: $bundle,
                stored: FieldStorage::Data,
            ),
        ]);
    }

    private function registerMeetingDateKey(): void
    {
        $this->registry->registerBundleUniqueKeys('media', 'members_document', [[
            'name' => 'media_members_document_meeting_date',
            'fields' => ['meeting_date'],
        ]]);
    }

    private function handler(): SqlSchemaHandler
    {
        return new SqlSchemaHandler($this->entityType, $this->database, $this->registry);
    }

    private function repository(): EntityRepository
    {
        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database), 'mid', fieldRegistry: $this->registry),
            new EventDispatcher(),
            database: $this->database,
            fieldRegistry: $this->registry,
        );
    }

    private function entity(string $uuid, string $bundle, ?string $meetingDate): EntityInterface
    {
        return $this->repository()->create([
            'uuid' => $uuid,
            'bundle' => $bundle,
            'label' => $uuid,
            'meeting_date' => $meetingDate,
        ]);
    }
}
