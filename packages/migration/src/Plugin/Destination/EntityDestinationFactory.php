<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Destination;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\MigrationIdMap;

/**
 * Constructor-injected factory for {@see EntityDestination}.
 *
 * Migration definitions declare their destination as a `MigrationDefinition`
 * field; service-locator lookup is forbidden by
 * `.claude/rules/entity-storage-invariant.md`. This factory keeps the
 * destination's collaborators (entity-type manager, gate, dispatcher) explicit
 * while leaving the per-migration parameters (entity type id, field map,
 * account) as factory-method arguments.
 *
 * Typical wiring inside a `HasMigrationsInterface` provider:
 *
 * ```php
 * $destination = $this->resolve(EntityDestinationFactory::class)->create(
 *     destinationEntityTypeId: 'user',
 *     migrationId: 'csv_users_to_accounts',
 *     idMap: $this->resolve(MigrationIdMap::class),
 *     repository: $repositoryForUser,
 *     account: $systemAccount,
 *     fieldMap: ['email_address' => 'mail'],
 * );
 * ```
 *
 * @api
 */
final class EntityDestinationFactory
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GateInterface $gate,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build a concrete `EntityDestination` for one migration.
     *
     * @param string $destinationEntityTypeId Target entity type id (must be registered).
     * @param string $migrationId Stable migration id used as the id-map partition key.
     * @param MigrationIdMap $idMap Stable id-map repository; injected here rather than at factory-construction so each migration can pin its own database connection.
     * @param EntityRepository $repository EntityRepository for the destination entity type. The caller must bind the repository to the matching `EntityType`; one destination = one entity type.
     * @param ?object $account Account passed to the gate on every write. Migration runs typically inject an elevated system account.
     * @param array<string, string> $fieldMap Destination-record key → storage field name. Empty for identity.
     */
    public function create(
        string $destinationEntityTypeId,
        string $migrationId,
        MigrationIdMap $idMap,
        EntityRepository $repository,
        ?object $account = null,
        array $fieldMap = [],
    ): EntityDestination {
        return new EntityDestination(
            destinationEntityTypeId: $destinationEntityTypeId,
            entityTypeManager: $this->entityTypeManager,
            entityRepository: $repository,
            idMap: $idMap,
            gate: $this->gate,
            eventDispatcher: $this->eventDispatcher,
            migrationId: $migrationId,
            account: $account,
            fieldMap: $fieldMap,
            logger: $this->logger,
        );
    }
}
