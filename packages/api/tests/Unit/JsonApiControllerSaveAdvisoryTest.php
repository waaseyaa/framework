<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerSaveAdvisoryTest extends TestCase
{
    private const string ACK_META = 'save_advisory_acknowledgements';

    private JsonApiController $controller;
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $this->manager = new EntityTypeManager(
            $dispatcher,
            null,
            static function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $resolver): EntityRepository {
                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                    $database,
                    validator: new EntityValidator(Validation::createValidator()),
                );
            },
        );

        $plain = new EntityType(
            id: 'advisory_plain',
            label: 'Advisory Plain',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $revisionable = new EntityType(
            id: 'advisory_revisionable',
            label: 'Advisory Revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $constrained = new EntityType(
            id: 'advisory_constrained',
            label: 'Advisory Constrained',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
            constraints: ['title' => [new NotBlank()]],
        );

        foreach ([$plain, $revisionable, $constrained] as $definition) {
            $this->manager->registerEntityType($definition);
            $schema = new SqlSchemaHandler($definition, $database);
            $schema->ensureTable();
            if ($definition->isRevisionable()) {
                $schema->ensureRevisionTable();
            }
        }

        $dispatcher->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            $field = $event->entity()->getEntityTypeId() === 'advisory_plain' ? 'label' : 'title';
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField(
                    $event->entity(),
                    'RESERVED_ROUTE_VALUE',
                    $field,
                    'This value is route-backed; review the fallback URL.',
                ),
            ], $event->saveContext());
        });

        $this->controller = new JsonApiController($this->manager, new ResourceSerializer($this->manager));
    }

    #[Test]
    public function create_returns_428_without_write_then_exact_acknowledgement_saves(): void
    {
        $body = $this->body('advisory_plain', ['label' => 'news']);

        $first = $this->controller->store('advisory_plain', $body);

        $this->assertAdvisoryError($first->toArray(), expectedField: 'label');
        self::assertSame(428, $first->statusCode);
        self::assertSame([], $this->repository('advisory_plain')->findBy([]));
        $token = $first->toArray()['errors'][0]['meta']['save_advisories'][0]['acknowledgement'];

        $body['data']['meta'][self::ACK_META] = [$token];
        $saved = $this->controller->store('advisory_plain', $body);

        self::assertSame(201, $saved->statusCode);
        self::assertSame('news', $saved->toArray()['data']['attributes']['label']);
        self::assertCount(1, $this->repository('advisory_plain')->findBy([]));
    }

    #[Test]
    public function plain_update_requires_a_token_for_the_changed_candidate(): void
    {
        $created = $this->acknowledgedCreate('advisory_plain', ['label' => 'legacy']);
        $id = $created['data']['id'];

        $first = $this->controller->update(
            'advisory_plain',
            $id,
            $this->body('advisory_plain', ['label' => 'news']),
        );
        $this->assertAdvisoryError($first->toArray(), expectedField: 'label');
        self::assertSame('legacy', $this->repository('advisory_plain')->findBy([])[0]->label());

        $token = $first->toArray()['errors'][0]['meta']['save_advisories'][0]['acknowledgement'];
        $saved = $this->controller->update(
            'advisory_plain',
            $id,
            $this->body('advisory_plain', ['label' => 'news'], acknowledgements: [$token]),
        );

        self::assertSame(200, $saved->statusCode);
        self::assertSame('news', $saved->toArray()['data']['attributes']['label']);
    }

    #[Test]
    public function expected_revision_update_threads_acknowledgement_with_the_concurrency_context(): void
    {
        $created = $this->acknowledgedCreate('advisory_revisionable', ['title' => 'v1']);
        $id = $created['data']['id'];
        $revision = $created['data']['attributes']['revision_id'];
        $body = $this->body('advisory_revisionable', ['title' => 'v2']);
        $body['data']['meta']['expected_revision_id'] = $revision;

        $first = $this->controller->update('advisory_revisionable', $id, $body);
        $this->assertAdvisoryError($first->toArray(), expectedField: 'title');

        $token = $first->toArray()['errors'][0]['meta']['save_advisories'][0]['acknowledgement'];
        $body['data']['meta'][self::ACK_META] = [$token];
        $saved = $this->controller->update('advisory_revisionable', $id, $body);

        self::assertSame(200, $saved->statusCode);
        self::assertSame('v2', $saved->toArray()['data']['attributes']['title']);
        self::assertGreaterThan($revision, $saved->toArray()['data']['attributes']['revision_id']);
    }

    #[Test]
    public function malformed_acknowledgement_metadata_is_400_and_never_reaches_save(): void
    {
        foreach ([
            'not-a-list',
            ['ABC'],
            [str_repeat('A', 64)],
            [str_repeat('a', 63)],
            [7],
            array_fill(0, 33, str_repeat('a', 64)),
        ] as $invalid) {
            $body = $this->body('advisory_plain', ['label' => 'news']);
            $body['data']['meta'][self::ACK_META] = $invalid;

            $document = $this->controller->store('advisory_plain', $body);

            self::assertSame(400, $document->statusCode);
            self::assertSame('400', $document->toArray()['errors'][0]['status']);
        }

        self::assertSame([], $this->repository('advisory_plain')->findBy([]));
    }

    #[Test]
    public function acknowledgement_cannot_bypass_entity_validation(): void
    {
        $body = $this->body(
            'advisory_constrained',
            ['title' => ''],
            acknowledgements: [str_repeat('a', 64)],
        );

        $document = $this->controller->store('advisory_constrained', $body);
        $error = $document->toArray()['errors'][0];

        self::assertSame(422, $document->statusCode);
        self::assertSame('422', $error['status']);
        self::assertArrayNotHasKey('meta', $error);
        self::assertSame([], $this->repository('advisory_constrained')->findBy([]));
    }

    /** @param array<string, mixed> $attributes */
    private function acknowledgedCreate(string $type, array $attributes): array
    {
        $body = $this->body($type, $attributes);
        $first = $this->controller->store($type, $body);
        $token = $first->toArray()['errors'][0]['meta']['save_advisories'][0]['acknowledgement'];
        $body['data']['meta'][self::ACK_META] = [$token];

        return $this->controller->store($type, $body)->toArray();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $acknowledgements
     * @return array<string, mixed>
     */
    private function body(string $type, array $attributes, array $acknowledgements = []): array
    {
        $body = ['data' => ['type' => $type, 'attributes' => $attributes]];
        if ($acknowledgements !== []) {
            $body['data']['meta'] = [self::ACK_META => $acknowledgements];
        }

        return $body;
    }

    /** @param array<string, mixed> $document */
    private function assertAdvisoryError(array $document, string $expectedField): void
    {
        $error = $document['errors'][0];
        self::assertSame('428', $error['status']);
        self::assertSame('Precondition Required', $error['title']);
        self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', $error['code']);
        self::assertSame('RESERVED_ROUTE_VALUE', $error['meta']['save_advisories'][0]['code']);
        self::assertSame($expectedField, $error['meta']['save_advisories'][0]['field']);
        self::assertSame('warning', $error['meta']['save_advisories'][0]['severity']);
    }

    private function repository(string $type): EntityRepository
    {
        $repository = $this->manager->getRepository($type);
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }
}
