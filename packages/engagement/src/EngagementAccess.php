<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** Shared current-policy checks for the service and mandatory write invariant. */
final readonly class EngagementAccess
{
    public function __construct(
        private DBALDatabase $database,
        private EntityTypeManagerInterface $entities,
        private EntityAccessHandler $access,
        public EngagementPolicyInterface $policy,
    ) {}

    public function actorId(AuthorizationPrincipalInterface $actor): int
    {
        $id = $actor->id();
        if (!$actor->isAuthenticated()) {
            throw new EngagementException('AUTH_REQUIRED', 401, 'Sign in to participate.');
        }
        if ((!is_int($id) && (!ctype_digit($id) || (string) (int) $id !== $id)) || (int) $id < 1) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'This account cannot participate.');
        }

        return (int) $id;
    }

    public function assertPolicy(): void
    {
        if ($this->policy->databaseIdentity() !== $this->database->databaseIdentity()) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Engagement policy is not bound to the current database.');
        }
    }

    public function root(AuthorizationPrincipalInterface $viewer, RootReference $reference, ?string $uuid = null): EntityInterface
    {
        $this->assertPolicy();
        if (in_array($reference->type, ['comment', 'reaction', 'follow'], true) || !$this->entities->hasDefinition($reference->type)) {
            throw self::unavailable();
        }
        return $this->resolvedRoot($viewer, $reference, $this->entities->getRepository($reference->type)->find($reference->id), $uuid);
    }

    public function resolvedRoot(AuthorizationPrincipalInterface $viewer, RootReference $reference, ?EntityInterface $root, ?string $uuid = null): EntityInterface
    {
        $this->assertPolicy();
        if (in_array($reference->type, ['comment', 'reaction', 'follow'], true)
            || $root === null || $root->getEntityTypeId() !== $reference->type
            || (string) $root->id() !== (string) $reference->id || $root->uuid() === ''
            || ($uuid !== null && $root->uuid() !== $uuid)
        ) {
            throw self::unavailable();
        }
        if (!$this->access->check($root, 'view', $viewer)->isAllowed() || !$this->policy->canViewRoot($viewer, $root)) {
            throw self::unavailable();
        }
        return $root;
    }

    public function assertWrite(AuthorizationPrincipalInterface $actor, EntityInterface $root, string $operation): void
    {
        $this->actorId($actor);
        $this->assertPolicy();
        if (!$this->policy->isEligible($actor)
            || !$this->policy->canInteract($actor, $root, $operation)
        ) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'This action is unavailable for this account.');
        }
    }

    public function canViewActor(AuthorizationPrincipalInterface $viewer, int $authorId, EntityInterface $root): bool
    {
        return $this->database->getConnection()->fetchOne('SELECT 1 FROM engagement_actor_erasure WHERE actor_id = ?', [$authorId]) === false
            && $this->policy->canViewActor($viewer, $authorId, $root);
    }

    public static function unavailable(): EngagementException
    {
        return new EngagementException('TARGET_UNAVAILABLE', 404, 'This content is unavailable.');
    }
}
