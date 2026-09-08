<?php

declare(strict_types=1);

/**
 * Community-events packaged reference-existence discriminator (#2981).
 *
 * Exercises the generated Event write path with production-like
 * EntityValidator + EntityIdentifierResolver wiring:
 * - JsonApiController::store() for HTTP status / durable-row checks
 * - EntityRepository::save() for property-path proof that refusals are
 *   reference-existence validation (not authorization / unrelated fields)
 *
 * Observed baseline: generated JsonApiGovernanceChecksTest boots repositories
 * without EntityValidator and seeds with validate:false, so its create-allowed
 * case can return 201 with organizer/venue ids that have no target rows. This
 * probe does not change that generated harness; it proves the kernel-equivalent
 * path the packaged application uses when validation is active.
 *
 * Usage: php community-events-reference-existence.php <consumer-root>
 */

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Validation\DatabaseValidationReadLedger;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;
use Waaseyaa\User\RoleRepository;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php community-events-reference-existence.php <consumer-root>\n");
    exit(2);
}

$consumer = $argv[1];
require $consumer . '/vendor/autoload.php';

$dispatcher = new SymfonyEventDispatcherAdapter();
$database = DBALDatabase::createSqlite();
$fieldRegistry = new FieldDefinitionRegistry();
$validator = EntityValidator::createDefault(new DatabaseValidationReadLedger($database));

/** @var EntityTypeManager|null $manager */
$manager = null;
$manager = new EntityTypeManager(
    $dispatcher,
    repositoryFactory: static function (string $entityTypeId, EntityTypeInterface $definition) use (&$manager, $dispatcher, $database, $validator, $fieldRegistry): EntityRepositoryInterface {
        $resolver = new SingleConnectionResolver($database);
        $backend = $definition->getPrimaryStorageBackend();
        $backend = (is_string($backend) && $backend !== '')
            ? $backend
            : ReservedBackendIds::SQL_BLOB;
        $entityLevelFields = [];
        if ($backend === ReservedBackendIds::SQL_COLUMN) {
            foreach ($definition->getFieldDefinitions() as $name => $fieldDefinition) {
                if (!$fieldDefinition instanceof FieldDefinition) {
                    continue;
                }
                if ($definition->isTranslatable() && $fieldDefinition->isTranslatable()) {
                    continue;
                }
                $entityLevelFields[$name] = $fieldDefinition;
            }
        }
        $schema = new SqlSchemaHandler(
            entityType: $definition,
            database: $database,
            fieldRegistry: $fieldRegistry,
            primaryBackendId: $backend,
            entityLevelFields: $entityLevelFields,
        );
        $schema->ensureTable();
        $revisionDriver = null;
        if ($definition->isRevisionable()) {
            $schema->ensureRevisionTable();
            $revisionDriver = new RevisionableStorageDriver($resolver, $definition);
        }

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $definition,
            new SqlStorageDriver($resolver, $definition->getKeys()['id']),
            $dispatcher,
            $revisionDriver,
            $database,
            validator: $validator,
            fieldRegistry: $fieldRegistry,
            entityReferenceResolver: new EntityIdentifierResolver($manager),
        );
    },
    fieldRegistry: $fieldRegistry,
);

foreach ([
    App\Entity\Organizer::class,
    App\Entity\Venue::class,
    App\Entity\Event::class,
] as $class) {
    $manager->registerEntityType(EntityType::fromClass($class), 'packaged-community-events-reference-existence');
}

$roles = RoleRepository::fromProviders([new App\Provider\ApplicationBlueprintGovernanceServiceProvider()]);
$contributorRole = $roles->get('contributor') ?? throw new RuntimeException('contributor role missing');
$account = AuthorizationPrincipalFactory::authenticated(
    42,
    roles: [$contributorRole->id],
    permissions: $contributorRole->permissions,
);

$accessHandler = new EntityAccessHandler([
    new App\Access\EventPolicy(),
    new App\Access\OrganizerPolicy(),
    new App\Access\VenuePolicy(),
]);
$controller = new JsonApiController(
    $manager,
    new ResourceSerializer($manager),
    $accessHandler,
    $account,
);

$eventRepository = $manager->getRepository('event');
$organizerRepository = $manager->getRepository('organizer');
$venueRepository = $manager->getRepository('venue');

$countRows = static function (string $table) use ($database): int {
    $row = $database->getConnection()->fetchAssociative(sprintf('SELECT COUNT(*) AS c FROM %s', $table));

    return (int) ($row['c'] ?? 0);
};

$seedOrganizer = static function (string $id, string $name) use ($organizerRepository): void {
    $entity = $organizerRepository->create([
        'id' => $id,
        'name' => $name,
        'email' => $name . '@example.test',
    ]);
    $entity->enforceIsNew();
    $organizerRepository->save($entity, validate: false);
};

$seedVenue = static function (string $id, string $name) use ($venueRepository): void {
    $entity = $venueRepository->create([
        'id' => $id,
        'name' => $name,
        'address' => '1 Test Street',
        'city' => 'Test City',
    ]);
    $entity->enforceIsNew();
    $venueRepository->save($entity, validate: false);
};

$eventAttributes = static function (int|string $organizerId, int|string $venueId): array {
    return [
        'title' => 'Reference existence probe',
        'starts_at' => '2026-09-21T17:00:00+00:00',
        'summary' => 'Packaged reference-existence discriminator',
        'organizer' => $organizerId,
        'venue' => $venueId,
    ];
};

$eventPayload = static function (int|string $organizerId, int|string $venueId) use ($eventAttributes): array {
    return [
        'data' => [
            'type' => 'event',
            'attributes' => $eventAttributes($organizerId, $venueId),
        ],
    ];
};

/**
 * @param list<string> $expectedFields
 */
$assertRefusal = static function (
    string $label,
    int|string $organizerId,
    int|string $venueId,
    array $expectedFields,
) use ($controller, $eventRepository, $eventAttributes, $eventPayload, $countRows): void {
    $before = $countRows('event');

    $paths = [];
    try {
        $entity = $eventRepository->create($eventAttributes($organizerId, $venueId));
        $entity->enforceIsNew();
        $eventRepository->save($entity);
        throw new RuntimeException("{$label}: repository save unexpectedly succeeded.");
    } catch (EntityValidationException $exception) {
        foreach ($exception->violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
    }

    foreach ($expectedFields as $field) {
        if (!in_array($field, $paths, true)) {
            throw new RuntimeException(
                "{$label}: expected EntityValidationException property path '{$field}', got ["
                . implode(', ', $paths)
                . ']',
            );
        }
    }
    foreach ($paths as $path) {
        if (!in_array($path, ['organizer', 'venue'], true)) {
            throw new RuntimeException("{$label}: unexpected validation property path '{$path}' (unrelated field).");
        }
    }
    if ($countRows('event') !== $before) {
        throw new RuntimeException("{$label}: repository path left a durable Event row.");
    }

    $document = $controller->store('event', $eventPayload($organizerId, $venueId));
    $wire = $document->toArray();
    $status = $document->statusCode;
    $error = $wire['errors'][0] ?? [];
    $detail = (string) ($error['detail'] ?? '');
    $title = (string) ($error['title'] ?? '');

    if ($status === 403 || str_contains(strtolower($title), 'forbidden')) {
        throw new RuntimeException("{$label}: JSON:API refused as authorization (403/Forbidden), not reference validation.");
    }
    if ($status !== 422) {
        throw new RuntimeException("{$label}: expected JSON:API 422, got {$status}: " . json_encode($wire, JSON_THROW_ON_ERROR));
    }
    if (!str_contains(strtolower($detail), 'validation')) {
        throw new RuntimeException("{$label}: JSON:API 422 detail does not indicate validation: {$detail}");
    }
    if ($countRows('event') !== $before) {
        throw new RuntimeException("{$label}: JSON:API path left a durable Event row ({$before} → " . $countRows('event') . ').');
    }
};

// Case A: missing Organizer, valid Venue
$seedVenue('10', 'Venue Ten');
$assertRefusal('missing-organizer', 404, 10, ['organizer']);

// Case B: valid Organizer, missing Venue
$seedOrganizer('20', 'Organizer Twenty');
$assertRefusal('missing-venue', 20, 404, ['venue']);

// Case C: both targets missing
$assertRefusal('both-missing', 501, 502, ['organizer', 'venue']);

// Authorization control: anonymous must be 403 (distinct from validation 422).
$anonController = new JsonApiController(
    $manager,
    new ResourceSerializer($manager),
    $accessHandler,
    AuthorizationPrincipalFactory::anonymous(),
);
$anonDoc = $anonController->store('event', $eventPayload(20, 10));
if ($anonDoc->statusCode !== 403) {
    throw new RuntimeException('auth-control: anonymous create expected 403, got ' . $anonDoc->statusCode);
}
if ($countRows('event') !== 0) {
    throw new RuntimeException('auth-control: anonymous path must not create Event rows.');
}

// Case D: both targets present
$beforeSuccess = $countRows('event');
$ok = $controller->store('event', $eventPayload(20, 10));
if ($ok->statusCode !== 201) {
    throw new RuntimeException('both-present: expected 201, got ' . $ok->statusCode . ': ' . json_encode($ok->toArray(), JSON_THROW_ON_ERROR));
}
$row = $database->getConnection()->fetchAssociative('SELECT title, organizer, venue FROM event ORDER BY id DESC LIMIT 1');
if ($row === false) {
    throw new RuntimeException('both-present: Event row missing after 201.');
}
if ((string) ($row['title'] ?? '') !== 'Reference existence probe') {
    throw new RuntimeException('both-present: title mismatch in persisted row.');
}
if ((string) ($row['organizer'] ?? '') !== '20' || (string) ($row['venue'] ?? '') !== '10') {
    throw new RuntimeException('both-present: persisted references mismatch: ' . json_encode($row, JSON_THROW_ON_ERROR));
}
if ($countRows('event') !== $beforeSuccess + 1) {
    throw new RuntimeException('both-present: expected exactly one new Event row.');
}

fwrite(STDOUT, "Community-events reference-existence discriminator passed.\n");
fwrite(STDOUT, "Observed baseline: generated JsonApiGovernanceChecksTest omits EntityValidator;\n");
fwrite(STDOUT, "#2989 substrate + this kernel-equivalent probe refuse missing Organizer/Venue rows.\n");
