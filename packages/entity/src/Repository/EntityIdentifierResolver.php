<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Repository;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Resolve an entity from an identifier that may be either its primary key or
 * its UUID.
 *
 * Surfaces that expose UUID as the public resource id (JSON:API, the admin SPA,
 * GraphQL, SSR canonical paths, media downloads) receive both shapes on the
 * same route parameter. This is the one place that decides which shape it is
 * and turns it into an entity.
 *
 * # This does NOT authorize access
 *
 * Resolution is repository infrastructure, not an access decision. The UUID
 * lookup runs with `accessCheck(false)` and never binds an acting account, so
 * it returns an entity the caller may have no right to see — deliberately, and
 * symmetrically with {@see EntityRepositoryInterface::find()}, which does not
 * access-check either.
 *
 * The posture is access-neutral because authorization depends on the caller's
 * operation and actor, which this class cannot know: a `view` filter is the
 * wrong check for a caller about to `update` or `delete`, and applying it here
 * would turn "you may not view this" into a misleading "not found" for an
 * editor who may legitimately edit it. Making resolution shape-dependent is
 * worse still: an id that resolves under one identifier shape and not the other
 * is an access model that leaks through the URL.
 *
 * **Every caller MUST run its own operation-specific access check on the
 * returned entity before exposing it or acting on it.** Typically
 * `EntityAccessHandler::check($entity, $operation, $account)`.
 *
 * @api
 */
final class EntityIdentifierResolver
{
    /**
     * RFC 4122 textual form, the shape {@see \Symfony\Component\Uid\Uuid} emits
     * and the only shape stored in a `uuid` entity key column.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

    /**
     * Resolve $identifier against $entityTypeId.
     *
     * The identifier is treated as a UUID only when the entity type declares a
     * `uuid` key AND the value is UUID-shaped; otherwise it is a primary key.
     * The two branches are exclusive: a UUID-shaped value is never also tried
     * as a primary key, because a `uuid` column and an id column never share a
     * value space.
     *
     * Returns null when nothing matches. Propagates the entity-type manager's
     * own failure for an unregistered $entityTypeId — callers that accept a
     * type id from a request should check `hasDefinition()` first.
     *
     * Read the class docblock before use: the returned entity is NOT authorized.
     */
    #[\NoDiscard('resolution result must be checked for null, then access-checked')]
    public function resolve(string $entityTypeId, int|string $identifier): ?EntityInterface
    {
        $identifier = (string) $identifier;
        if ($identifier === '') {
            return null;
        }

        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $uuidKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys()['uuid'] ?? null;

        if ($uuidKey === null || preg_match(self::UUID_PATTERN, $identifier) !== 1) {
            return $repository->find($identifier);
        }

        // Access-neutral by contract — see the class docblock.
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition($uuidKey, $identifier)
            ->range(0, 1)
            ->execute();

        return $ids === [] ? null : $repository->find((string) reset($ids));
    }
}
