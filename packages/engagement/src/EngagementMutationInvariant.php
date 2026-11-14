<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantContext;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantInterface;
use Waaseyaa\EntityStorage\Mutation\EntityMutationOperation;

/** Repository backstop. Opening a scope never substitutes for these current checks. */
final readonly class EngagementMutationInvariant implements EntityMutationInvariantInterface
{
    public function __construct(
        private DBALDatabase $database,
        private EngagementAccess|\Closure $access,
        private EngagementMutationScope $scope,
    ) {}

    public function assertMutation(EntityMutationInvariantContext $context): void
    {
        $grant = $this->scope->current();
        if ($grant === null || $context->database !== $this->database
            || $this->database->getConnection()->getTransactionNestingLevel() < 1
            || $context->principal !== $grant['actor']
        ) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'Use the engagement service for this mutation.');
        }
        $entity = $context->proposed ?? $context->original;
        if (!$entity instanceof Comment && !$entity instanceof Reaction) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'This is not an engagement mutation.');
        }
        if ($grant['operation'] === 'migration') {
            // The migration runner is trusted infrastructure. Its payload is never served
            // until migration completes, and unresolved roots must remain quarantined.
            // Canonical legacy column originals may expose persisted false as an
            // empty string or zero; only already-blank, authorless shells qualify.
            $purged = $entity->get('lifecycle') === 'purged' && (int) $entity->get('user_id') === 0
                && (!$entity instanceof Comment || ($entity->get('body') === '' && in_array($entity->get('status'), [false, 0, '0', ''], true)));
            if (!$purged && $entity->get('lifecycle') !== 'quarantined'
                && ((string) $entity->get('root_uuid') === '' || (int) $entity->get('user_id') < 1)
            ) {
                throw new EngagementException('VALIDATION_FAILED', 422, 'Legacy engagement needs a resolved root and author.');
            }
            return;
        }
        if ($entity->uuid() !== $grant['uuid']) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'The mutation scope does not match this content.');
        }
        foreach ($grant['expected'] as $field => $value) {
            if ($entity->get($field) !== $value) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'A mutation callback changed the authorized payload.');
            }
        }
        // Migration and retention can run before the consuming policy is installed.
        $access = $this->access instanceof EngagementAccess ? $this->access : ($this->access)();
        $actor = $grant['actor'];
        $root = $access->root($actor, new RootReference((string) $entity->get('target_type'), (int) $entity->get('target_id')), (string) $entity->get('root_uuid'));
        $operation = $grant['operation'];
        if ($operation === 'moderate') {
            $access->actorId($actor);
            if (!$access->policy->isEligible($actor) || !$access->policy->canModerate($actor, $root)) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Moderation is unavailable for this account.');
            }
        } else {
            $access->assertWrite($actor, $root, $operation === 'unlike' ? 'like' : $operation);
            if ((int) $entity->get('user_id') !== $access->actorId($actor)) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'The author must be the current account.');
            }
        }
        if ($context->original !== null) {
            foreach (['user_id', 'target_type', 'target_id', 'root_uuid', 'created_at'] as $field) {
                if ($context->original->get($field) !== $entity->get($field)) {
                    throw new EngagementException('ACTION_FORBIDDEN', 403, 'Engagement identity is immutable.');
                }
            }
            if ($entity instanceof Comment && $context->original->get('parent_comment_uuid') !== $entity->get('parent_comment_uuid')) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Reply identity is immutable.');
            }
        }
        if ($entity instanceof Reaction) {
            if ($entity->get('reaction_type') !== 'like' || !in_array($operation, ['like', 'unlike'], true)
                || ($context->operation === EntityMutationOperation::Delete) !== ($operation === 'unlike')
            ) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Invalid like transition.');
            }
            return;
        }
        if ($context->operation === EntityMutationOperation::Delete) {
            throw new EngagementException('ACTION_FORBIDDEN', 403, 'Remove comments through their lifecycle.');
        }
        $parentUuid = $entity->get('parent_comment_uuid');
        if ($context->operation === EntityMutationOperation::Create && $parentUuid !== null) {
            $parents = iterator_to_array($this->database->select('engagement_comment_index')
                ->fields('engagement_comment_index')
                ->condition('comment_uuid', $parentUuid)->execute());
            $parent = $parents[0] ?? null;
            if ($parent === null || $parent['root_uuid'] !== $root->uuid()
                || $parent['parent_uuid'] !== null || $parent['lifecycle'] !== 'active'
                || !$access->canViewActor($actor, (int) $parent['author_id'], $root)
            ) {
                throw EngagementAccess::unavailable();
            }
        }
        if (in_array($operation, ['remove', 'moderate'], true)) {
            if ($entity->get('body') !== '' || $entity->get('status') !== false || $entity->get('lifecycle') !== 'removed'
                || $entity->get('removal_actor_id') !== $access->actorId($actor)
                || $entity->get('removal_kind') !== ($operation === 'moderate' ? 'moderator' : 'author')) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Removal must erase the published text.');
            }
        } elseif (!in_array($operation, ['comment', 'edit'], true)
            || $entity->get('lifecycle') !== 'active'
            || $entity->get('status') !== true
            || CommentText::normalize((string) $entity->get('body')) !== $entity->get('body')
        ) {
            throw new EngagementException('VALIDATION_FAILED', 422, 'Invalid comment transition.');
        }
        if ($context->original !== null && $context->original->get('lifecycle') !== 'active') {
            throw EngagementAccess::unavailable();
        }
    }
}
