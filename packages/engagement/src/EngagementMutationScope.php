<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Entity\EntityInterface;

/** @internal Shared only by the domain service, its invariant and trusted upgrade runner. */
final class EngagementMutationScope
{
    /** @var array{actor: AuthorizationPrincipalInterface, operation: string, uuid: ?string, expected: array<string,mixed>}|null */
    private ?array $active = null;

    /** @param (\Closure(): \Waaseyaa\Access\EntityAccessHandler)|null $rootAccessResolver */
    public function __construct(
        private readonly AccountContextInterface $accounts,
        private readonly AccountFieldReadScopeInterface $reads,
        private readonly ?\Closure $rootAccessResolver = null,
    ) {}

    /** @return array{actor: AuthorizationPrincipalInterface, operation: string, uuid: ?string, expected: array<string,mixed>}|null */
    public function current(): ?array
    {
        return $this->active;
    }

    /** Resolve the final kernel handler after two-phase policy discovery finishes. */
    public function rootAccessHandler(): ?\Waaseyaa\Access\EntityAccessHandler
    {
        return $this->rootAccessResolver === null ? null : ($this->rootAccessResolver)();
    }

    public function permitsRead(AuthorizationPrincipalInterface $actor, string $uuid): bool
    {
        return $this->active !== null
            && $this->active['actor'] === $actor
            && ($this->active['operation'] === 'migration' || $this->active['uuid'] === $uuid);
    }

    public function run(AuthorizationPrincipalInterface $actor, string $operation, EntityInterface $entity, \Closure $callback): mixed
    {
        return $this->enter($actor, $operation, $entity->uuid(), function () use ($entity, $callback): mixed {
            $fields = ['user_id', 'target_type', 'target_id', 'root_uuid', 'lifecycle', 'created_at', 'quarantined_at'];
            $fields = $entity instanceof Comment
                ? [...$fields, 'body', 'parent_comment_uuid', 'status', 'edited_at', 'removed_at', 'removal_actor_id', 'removal_kind']
                : [...$fields, 'reaction_type'];
            foreach ($fields as $field) {
                $this->active['expected'][$field] = $entity->get($field);
            }

            return $callback();
        });
    }

    /** Explicit maintenance authority, never an actor-facing endpoint. */
    public function runMigration(\Closure $callback): mixed
    {
        return $this->enter(
            new AuthorizationPrincipal(0, false, [], ['administer content'], 'engagement-migration'),
            'migration',
            null,
            $callback,
        );
    }

    private function enter(AuthorizationPrincipalInterface $actor, string $operation, ?string $uuid, \Closure $callback): mixed
    {
        if ($this->active !== null) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'A nested engagement mutation is not allowed.');
        }
        $previous = $this->accounts->current();
        $this->active = ['actor' => $actor, 'operation' => $operation, 'uuid' => $uuid, 'expected' => []];
        $this->accounts->set($actor);
        try {
            return $this->reads->run($actor, $callback);
        } finally {
            $this->active = null;
            $this->accounts->set($previous);
        }
    }
}
