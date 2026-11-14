<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\AggregateMutationRepositoryInterface;
use Waaseyaa\EntityStorage\Exception\EntityMutationCommittedSideEffectsFailedException;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;

/** Supported, transactionally guarded likes and text discussions. */
final class EngagementService
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly DBALDatabase $database,
        private readonly EntityTypeManagerInterface $entities,
        private readonly EngagementAccess $access,
        private readonly EngagementMutationScope $scope,
        private readonly EngagementTokens $tokens,
        private readonly EngagementRateLimiter $limiter,
        private readonly AccountFieldReadScopeInterface $reads,
        private readonly ?AuditWriterInterface $audit = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function issueCommentKey(AuthorizationPrincipalInterface $actor, RootReference $root, ?string $parent = null): string
    {
        return $this->mutate($actor, $root, 'comment', 'key', function (EntityInterface $target, int $uid) use ($actor, $parent): string {
            $this->assertParent($actor, $target, $parent);
            return $this->tokens->issueSubmission((string) $uid, $target->getEntityTypeId(), $target->uuid(), $parent);
        });
    }

    public function setLiked(AuthorizationPrincipalInterface $actor, RootReference $root, bool $liked): LikeState
    {
        return $this->mutate($actor, $root, 'like', 'like', function (EntityInterface $target, int $uid) use ($actor, $liked): LikeState {
            $connection = $this->database->getConnection();
            $identity = $connection->fetchAssociative(
                'SELECT reaction_uuid FROM engagement_like_identity WHERE actor_id = ? AND root_type = ? AND root_uuid = ? AND reaction_type = ?',
                [$uid, $target->getEntityTypeId(), $target->uuid(), 'like'],
            );
            $repository = $this->entities->getRepository('reaction');
            if ($liked && $identity === false) {
                $entity = $repository->create([
                    'uuid' => Uuid::v4()->toRfc4122(), 'reaction_type' => 'like', 'user_id' => $uid,
                    'target_type' => $target->getEntityTypeId(), 'target_id' => (int) $target->id(),
                    'root_uuid' => $target->uuid(), 'lifecycle' => 'active', 'created_at' => ($this->clock)(),
                ]);
                $this->scope->run($actor, 'like', $entity, fn(): int => $repository->save($entity));
                $connection->insert('engagement_like_identity', [
                    'actor_id' => $uid, 'root_type' => $target->getEntityTypeId(), 'root_id' => (int) $target->id(),
                    'root_uuid' => $target->uuid(), 'reaction_type' => 'like', 'reaction_uuid' => $entity->uuid(),
                ]);
            } elseif (!$liked && $identity !== false) {
                $matches = $repository->findBy(['uuid' => $identity['reaction_uuid']]);
                $entity = $matches[0] ?? null;
                if (!$entity instanceof Reaction) {
                    throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Like identity needs repair.');
                }
                $this->scope->run($actor, 'unlike', $entity, fn() => $repository->delete($entity));
                $connection->delete('engagement_like_identity', ['reaction_uuid' => $entity->uuid()]);
            }
            return $this->likeState($actor, $target);
        });
    }

    public function createComment(AuthorizationPrincipalInterface $actor, RootReference $root, string $text, string $submissionKey, ?string $parent = null): CommentView
    {
        $text = CommentText::normalize($text);
        return $this->mutate($actor, $root, 'comment', 'comment', function (EntityInterface $target, int $uid) use ($actor, $text, $submissionKey, $parent): CommentView {
            $claims = $this->tokens->readSubmission($submissionKey);
            if ($claims['actor'] !== (string) $uid) {
                throw EngagementAccess::unavailable();
            }
            if ($claims['rootType'] !== $target->getEntityTypeId() || $claims['rootUuid'] !== $target->uuid() || $claims['parentUuid'] !== $parent) {
                throw new EngagementException('SUBMISSION_CONFLICT', 409, 'The submission key belongs to different content.');
            }
            $key = hash('sha256', json_encode([$claims['installation'], $claims['actor'], $claims['nonce']], JSON_THROW_ON_ERROR));
            $hash = hash('sha256', json_encode([$claims['rootType'], $claims['rootUuid'], $parent, $text], JSON_THROW_ON_ERROR));
            $connection = $this->database->getConnection();
            $receipt = $connection->fetchAssociative('SELECT * FROM engagement_receipts WHERE key_digest = ?', [$key]);
            if ($receipt !== false) {
                if ((int) $receipt['actor_id'] !== $uid || !hash_equals($receipt['request_hash'], $hash)) {
                    throw new EngagementException('SUBMISSION_CONFLICT', 409, 'This submission key has already been used for different text.');
                }
                $row = $this->row($receipt['result_uuid']);
                $projection = $this->projection($actor, $target, $row, false, true);
                if ($projection === null || $row['lifecycle'] !== 'active') {
                    throw EngagementAccess::unavailable();
                }
                return $projection;
            }
            $this->assertParent($actor, $target, $parent);
            $repository = $this->entities->getRepository('comment');
            $entity = $repository->create([
                'uuid' => Uuid::v4()->toRfc4122(), 'body' => $text, 'user_id' => $uid,
                'target_type' => $target->getEntityTypeId(), 'target_id' => (int) $target->id(),
                'root_uuid' => $target->uuid(), 'parent_comment_uuid' => $parent,
                'status' => true, 'lifecycle' => 'active', 'created_at' => ($this->clock)(),
            ]);
            $row = $this->scope->run($actor, 'comment', $entity, function () use ($repository, $entity): array {
                $repository->save($entity);
                $fresh = $repository->find((string) $entity->id());
                if (!$fresh instanceof Comment) {
                    throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'The comment could not be reloaded.');
                }
                return $this->index($fresh);
            });
            $connection->insert('engagement_receipts', [
                'key_digest' => $key, 'actor_id' => $uid, 'request_hash' => $hash,
                'result_uuid' => $row['comment_uuid'], 'created_at' => ($this->clock)(), 'expires_at' => $claims['expiresAt'],
            ]);
            return $this->projection($actor, $target, $row, false) ?? throw EngagementAccess::unavailable();
        });
    }

    public function editComment(AuthorizationPrincipalInterface $actor, string $comment, string $text, int $expectedRevision): CommentView
    {
        $text = CommentText::normalize($text);
        $reference = $this->prepareReference($actor, $comment);
        return $this->mutate($actor, $reference, 'edit', 'edit', function (EntityInterface $root, int $uid) use ($actor, $comment, $text, $expectedRevision): CommentView {
            $row = $this->row($comment);
            $this->assertRowRoot($row, $root);
            $entity = $this->commentEntity($row);
            if (!$this->visible($actor, $root, $row)) {
                throw EngagementAccess::unavailable();
            }
            if ((int) $row['author_id'] !== $uid) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Only the current author can edit this comment.');
            }
            $revision = $entity->mutationToken()?->aggregateVersion;
            if ($expectedRevision < 1 || $revision !== $expectedRevision) {
                throw new EngagementException('REVISION_CONFLICT', 409, 'This comment changed. Reload it before editing.');
            }
            if ($entity->get('body') === $text) {
                return $this->projection($actor, $root, $row, false) ?? throw EngagementAccess::unavailable();
            }
            $entity->set('body', $text)->set('edited_at', ($this->clock)());
            $repository = $this->entities->getRepository('comment');
            if (!$repository instanceof AggregateMutationRepositoryInterface) {
                throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Comment mutation authority is unavailable.');
            }
            $row = $this->scope->run($actor, 'edit', $entity, function () use ($repository, $entity, $row): array {
                $repository->saveAggregateMutation($entity, static function (): void {});
                return $this->index($this->commentEntity($row));
            });
            return $this->projection($actor, $root, $row, false) ?? throw EngagementAccess::unavailable();
        });
    }

    public function removeComment(AuthorizationPrincipalInterface $actor, string $comment): void
    {
        $this->remove($actor, $comment, false, null);
    }

    public function moderateRemove(AuthorizationPrincipalInterface $actor, string $comment, string $reason): void
    {
        $this->remove($actor, $comment, true, CommentText::normalize($reason, 500));
    }

    private function remove(AuthorizationPrincipalInterface $actor, string $uuid, bool $moderation, ?string $reason): void
    {
        $reference = $this->prepareReference($actor, $uuid);
        $operation = $moderation ? 'moderate' : 'remove';
        $this->mutate($actor, $reference, $operation, null, function (EntityInterface $root, int $uid) use ($actor, $uuid, $operation, $moderation, $reason): void {
            $row = $this->row($uuid);
            $this->assertRowRoot($row, $root);
            if (!$moderation && (int) $row['author_id'] !== $uid && !$this->visible($actor, $root, $row)) {
                throw EngagementAccess::unavailable();
            }
            if (!$moderation && (int) $row['author_id'] !== $uid) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Only the current author can remove this comment.');
            }
            if ($row['lifecycle'] === 'removed') {
                return;
            }
            if ($row['lifecycle'] !== 'active') {
                throw EngagementAccess::unavailable();
            }
            if ($moderation && $this->audit === null) {
                throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Moderation audit is unavailable.');
            }
            // Read under the bound removal scope, including when a moderator is
            // removing content currently hidden by a personal block.
            $entity = $this->commentEntity($row);
            $entity->set('body', '')->set('status', false)->set('lifecycle', 'removed')->set('removed_at', ($this->clock)())->set('removal_actor_id', $uid)->set('removal_kind', $moderation ? 'moderator' : 'author');
            $repository = $this->entities->getRepository('comment');
            $this->scope->run($actor, $operation, $entity, function () use ($repository, $entity, $row): void {
                $repository->save($entity);
                $this->index($this->commentEntity($row));
            });
            $this->database->query('UPDATE engagement_policy_state SET visibility_generation = visibility_generation + 1 WHERE id = 1');
            if ($moderation) {
                $this->audit?->record(new AuditEventDescriptor(
                    AuditEventKind::EntityDelete,
                    $uid,
                    'comment/' . $uuid,
                    'allowed',
                    'notice',
                    'comment',
                    $uuid,
                    ['operation' => 'moderator-removal', 'reason' => $reason],
                ));
            }
        });
    }

    public function getComment(AuthorizationPrincipalInterface $viewer, string $comment): CommentView
    {
        return $this->read($viewer, function () use ($viewer, $comment): CommentView {
            $row = $this->row($comment);
            $root = $this->access->root($viewer, $this->reference($row), $row['root_uuid']);
            return $this->projection($viewer, $root, $row, true) ?? throw EngagementAccess::unavailable();
        });
    }

    /** @param list<RootReference> $roots @return list<array{root:RootReference,liked:bool,likeCount:int,commentCount:int,topLevelCount:int}> */
    public function summaries(AuthorizationPrincipalInterface $viewer, array $roots): array
    {
        $unique = [];
        foreach ($roots as $reference) {
            $unique[$reference->type . ':' . $reference->id] = $reference;
        }
        if (count($unique) > 50) {
            throw new EngagementException('VALIDATION_FAILED', 422, 'Read at most 50 distinct roots at once.');
        }
        return $this->read($viewer, function () use ($viewer, $unique): array {
            if ($unique === []) {
                return [];
            }
            $byType = [];
            foreach ($unique as $reference) {
                if (in_array($reference->type, ['comment', 'reaction', 'follow'], true) || !$this->entities->hasDefinition($reference->type)) {
                    throw EngagementAccess::unavailable();
                }
                $byType[$reference->type][] = $reference->id;
            }
            $loaded = [];
            foreach ($byType as $type => $ids) {
                foreach ($this->entities->getRepository($type)->findMany($ids) as $entity) {
                    $loaded[$type . ':' . $entity->id()] = $entity;
                }
            }
            $targets = $results = $conditions = $bindings = [];
            foreach ($unique as $key => $reference) {
                $target = $this->access->resolvedRoot($viewer, $reference, $loaded[$key] ?? null);
                $identity = $target->getEntityTypeId() . ':' . $target->uuid();
                $targets[$identity] = $target;
                $results[$identity] = ['root' => $reference, 'liked' => false, 'likeCount' => 0, 'commentCount' => 0, 'topLevelCount' => 0];
                $conditions[] = '(root_type = ? AND root_uuid = ?)';
                $bindings[] = $target->getEntityTypeId();
                $bindings[] = $target->uuid();
            }
            $where = implode(' OR ', $conditions);
            // One grouped read per projection across the requested roots. Authorization
            // is evaluated against this same canonical read snapshot.
            $likes = $this->database->getConnection()->executeQuery(
                "SELECT root_type, root_uuid, actor_id FROM engagement_like_identity WHERE reaction_type = 'like' AND (" . $where . ')',
                $bindings,
            );
            while (($row = $likes->fetchAssociative()) !== false) {
                $key = $row['root_type'] . ':' . $row['root_uuid'];
                if ($this->access->canViewActor($viewer, (int) $row['actor_id'], $targets[$key])) {
                    ++$results[$key]['likeCount'];
                    $results[$key]['liked'] = $results[$key]['liked'] || ($viewer->isAuthenticated() && (string) $viewer->id() === (string) $row['actor_id']);
                }
            }
            $comments = $this->database->getConnection()->executeQuery(
                'SELECT root_type, root_uuid, author_id, parent_uuid, lifecycle FROM engagement_comment_index WHERE ' . $where,
                $bindings,
            );
            while (($row = $comments->fetchAssociative()) !== false) {
                $key = $row['root_type'] . ':' . $row['root_uuid'];
                if ($this->visible($viewer, $targets[$key], $row)) {
                    ++$results[$key]['commentCount'];
                    $results[$key]['topLevelCount'] += $row['parent_uuid'] === null ? 1 : 0;
                }
            }
            return array_values($results);
        });
    }

    public function comments(AuthorizationPrincipalInterface $viewer, RootReference $root, ?string $cursor = null, int $limit = 20): CommentPage
    {
        return $this->page($viewer, $root, null, $cursor, $limit);
    }

    public function replies(AuthorizationPrincipalInterface $viewer, RootReference $root, string $parent, ?string $cursor = null, int $limit = 20): CommentPage
    {
        return $this->page($viewer, $root, $parent, $cursor, $limit);
    }

    /** Resolve the immutable root within the same read snapshot as the reply page. */
    public function repliesForComment(AuthorizationPrincipalInterface $viewer, string $parent, ?string $cursor = null, int $limit = 20): CommentPage
    {
        return $this->read($viewer, function () use ($viewer, $parent, $cursor, $limit): CommentPage {
            return $this->page($viewer, $this->reference($this->row($parent)), $parent, $cursor, $limit);
        });
    }

    private function page(AuthorizationPrincipalInterface $viewer, RootReference $reference, ?string $parent, ?string $cursor, int $limit): CommentPage
    {
        if ($limit < 1 || $limit > 50) {
            throw new EngagementException('VALIDATION_FAILED', 422, 'Page size must be between 1 and 50.');
        }
        return $this->read($viewer, function () use ($viewer, $reference, $parent, $cursor, $limit): CommentPage {
            $root = $this->access->root($viewer, $reference);
            if ($parent !== null) {
                $parentRow = $this->row($parent);
                $this->assertRowRoot($parentRow, $root);
                if ($parentRow['parent_uuid'] !== null || $this->projection($viewer, $root, $parentRow, true) === null) {
                    throw EngagementAccess::unavailable();
                }
            }
            $generation = $this->generation($viewer);
            $connection = $this->database->getConnection();
            $claims = $cursor === null ? [
                'rootType' => $root->getEntityTypeId(), 'rootUuid' => $root->uuid(), 'parent' => $parent,
                'viewer' => (string) $viewer->id(), 'authenticated' => $viewer->isAuthenticated(),
                'generation' => $generation, 'limit' => $limit, 'lastTime' => -1, 'lastId' => 0,
                'highWater' => (int) $connection->fetchOne('SELECT COALESCE(MAX(comment_id), 0) FROM engagement_comment_index'),
            ] : $this->tokens->readCursor($cursor);
            foreach (['rootType' => $root->getEntityTypeId(), 'rootUuid' => $root->uuid(), 'parent' => $parent,
                'viewer' => (string) $viewer->id(), 'authenticated' => $viewer->isAuthenticated(), 'limit' => $limit] as $key => $value) {
                if (!array_key_exists($key, $claims) || $claims[$key] !== $value) {
                    throw new EngagementException('INVALID_CURSOR', 400, 'Cursor does not match this discussion.');
                }
            }
            if (($claims['generation'] ?? null) !== $generation) {
                throw new EngagementException('CURSOR_STALE', 409, 'Discussion permissions changed. Restart the page.');
            }
            foreach (['lastTime', 'lastId', 'highWater'] as $key) {
                if (!is_int($claims[$key] ?? null)) {
                    throw new EngagementException('INVALID_CURSOR', 400, 'Invalid discussion position.');
                }
            }
            $parentSql = $parent === null ? 'parent_uuid IS NULL' : 'parent_uuid = ?';
            $args = [$root->getEntityTypeId(), $root->uuid()];
            if ($parent !== null) {
                $args[] = $parent;
            }
            $args = [...$args, $claims['highWater'], $claims['lastTime'], $claims['lastTime'], $claims['lastId']];
            $rows = $connection->fetchAllAssociative(
                'SELECT * FROM engagement_comment_index WHERE root_type = ? AND root_uuid = ? AND ' . $parentSql
                . ' AND comment_id <= ? AND (created_at > ? OR (created_at = ? AND comment_id > ?)) ORDER BY created_at, comment_id LIMIT 200',
                $args,
            );
            $items = [];
            $position = null;
            $consumed = 0;
            foreach ($rows as $row) {
                ++$consumed;
                $position = $row;
                $view = $this->projection($viewer, $root, $row, $parent === null);
                if ($view !== null) {
                    $items[] = $view;
                }
                if (count($items) === $limit) {
                    break;
                }
            }
            $more = $position !== null && (count($rows) > $consumed || $consumed === 200);
            $next = null;
            if ($more) {
                $claims['lastTime'] = (int) $position['created_at'];
                $claims['lastId'] = (int) $position['comment_id'];
                $next = $this->tokens->issueCursor($claims);
            }
            return new CommentPage($items, $next, $more);
        });
    }

    private function mutate(AuthorizationPrincipalInterface $actor, RootReference $reference, string $operation, ?string $quota, \Closure $callback): mixed
    {
        return $this->translateFailures(function () use ($actor, $reference, $operation, $quota, $callback): mixed {
            $this->ready();
            if ($this->database->getConnection()->isTransactionActive()) {
                throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Engagement owns its mutation transaction.');
            }
            return $this->reads->run($actor, function () use ($actor, $reference, $operation, $quota, $callback): mixed {
                $uid = $this->access->actorId($actor);
                $initial = $this->access->root($actor, $reference);
                $this->authorize($actor, $initial, $operation);
                if ($quota !== null) {
                    $this->limiter->consume((string) $uid, $quota);
                }
                return $this->database->transactional(function () use ($actor, $reference, $operation, $callback, $uid, $initial): mixed {
                    $this->database->query('UPDATE engagement_policy_state SET visibility_generation = visibility_generation WHERE id = 1');
                    $root = $this->access->root($actor, $reference, $initial->uuid());
                    $this->authorize($actor, $root, $operation);
                    return $callback($root, $uid);
                });
            });
        });
    }

    private function authorize(AuthorizationPrincipalInterface $actor, EntityInterface $root, string $operation): void
    {
        if ($operation === 'moderate') {
            $this->access->actorId($actor);
            if (!$this->access->policy->isEligible($actor) || !$this->access->policy->canModerate($actor, $root)) {
                throw new EngagementException('ACTION_FORBIDDEN', 403, 'Moderation is unavailable for this account.');
            }
            return;
        }
        $this->access->assertWrite($actor, $root, $operation);
    }

    private function read(AuthorizationPrincipalInterface $viewer, \Closure $callback): mixed
    {
        return $this->translateFailures(function () use ($viewer, $callback): mixed {
            $this->ready();
            $this->access->assertPolicy();
            return $this->reads->run($viewer, function () use ($callback): mixed {
                if ($this->database->getConnection()->isTransactionActive()) {
                    return $callback();
                }
                $transaction = $this->database->consistentReadTransaction('engagement-read');
                try {
                    $result = $callback();
                } catch (\Throwable $failure) {
                    $transaction->rollBack();
                    throw $failure;
                }
                $transaction->commit();
                return $result;
            });
        });
    }

    private function ready(): void
    {
        $progress = $this->database->getConnection()->fetchAllAssociative('SELECT id, complete FROM engagement_upgrade_progress');
        $complete = array_column($progress, 'complete', 'id');
        if ((int) ($complete['comment'] ?? 0) !== 1 || (int) ($complete['reaction'] ?? 0) !== 1) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Complete the engagement data upgrade before serving discussions.');
        }
    }

    private function translateFailures(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (EngagementException $failure) {
            throw $failure;
        } catch (EntityMutationConflictException $failure) {
            throw new EngagementException('REVISION_CONFLICT', 409, 'This content changed. Reload it before retrying.', null, $failure);
        } catch (TransactionCompletionException|EntityMutationCommittedSideEffectsFailedException|DatabaseException $failure) {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Engagement is temporarily unavailable. Retry with the same submission key.', null, $failure);
        }
    }

    private function prepareReference(AuthorizationPrincipalInterface $actor, string $uuid): RootReference
    {
        $this->access->actorId($actor);
        return $this->translateFailures(function () use ($uuid): RootReference {
            $this->ready();
            return $this->reference($this->row($uuid));
        });
    }

    /** @return array<string,mixed> */
    private function row(string $uuid): array
    {
        $row = $this->database->getConnection()->fetchAssociative('SELECT * FROM engagement_comment_index WHERE comment_uuid = ?', [$uuid]);
        if ($row === false) {
            throw EngagementAccess::unavailable();
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function reference(array $row): RootReference
    {
        if ($row['lifecycle'] === 'quarantined' || $row['root_type'] === '' || (int) $row['root_id'] < 1 || $row['root_uuid'] === '') {
            throw EngagementAccess::unavailable();
        }
        return new RootReference($row['root_type'], (int) $row['root_id']);
    }

    /** @param array<string,mixed> $row */
    private function assertRowRoot(array $row, EntityInterface $root): void
    {
        if ($row['root_type'] !== $root->getEntityTypeId() || $row['root_uuid'] !== $root->uuid() || (int) $row['root_id'] !== (int) $root->id()) {
            throw EngagementAccess::unavailable();
        }
    }

    /** @param array<string,mixed> $row */
    private function commentEntity(array $row): Comment
    {
        $entity = $this->entities->getRepository('comment')->find((int) $row['comment_id']);
        if (!$entity instanceof Comment || $entity->uuid() !== $row['comment_uuid']) {
            throw EngagementAccess::unavailable();
        }
        return $entity;
    }

    /** @return array<string,mixed> */
    private function index(Comment $entity): array
    {
        $row = [
            'comment_uuid' => $entity->uuid(), 'comment_id' => (int) $entity->id(),
            'root_type' => $entity->get('target_type'), 'root_id' => (int) $entity->get('target_id'),
            'root_uuid' => $entity->get('root_uuid'), 'parent_uuid' => $entity->get('parent_comment_uuid'),
            'author_id' => (int) $entity->get('user_id'), 'created_at' => (int) $entity->get('created_at'),
            'lifecycle' => $entity->get('lifecycle'), 'removed_at' => $entity->get('removed_at'),
        ];
        $connection = $this->database->getConnection();
        $existing = $connection->fetchOne('SELECT 1 FROM engagement_comment_index WHERE comment_uuid = ?', [$entity->uuid()]);
        if ($existing === false) {
            $connection->insert('engagement_comment_index', $row);
        } else {
            $connection->update('engagement_comment_index', $row, ['comment_uuid' => $entity->uuid()]);
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function visible(AuthorizationPrincipalInterface $viewer, EntityInterface $root, array $row): bool
    {
        return $row['lifecycle'] === 'active' && $this->access->canViewActor($viewer, (int) $row['author_id'], $root);
    }

    /** @param array<string,mixed> $row */
    private function projection(AuthorizationPrincipalInterface $viewer, EntityInterface $root, array $row, bool $placeholder, bool $replayed = false): ?CommentView
    {
        $this->assertRowRoot($row, $root);
        $replies = $row['parent_uuid'] === null ? $this->visibleReplyCount($viewer, $root, $row['comment_uuid']) : 0;
        if ($this->visible($viewer, $root, $row)) {
            $entity = $this->commentEntity($row);
            if (!$this->scope->rootAccessHandler()?->check($entity, 'view', $viewer)->isAllowed()) {
                return null;
            }
            return new CommentView(
                $entity->uuid(),
                (int) $row['author_id'],
                (string) $entity->get('body'),
                (int) $row['created_at'],
                $entity->get('edited_at') === null ? null : (int) $entity->get('edited_at'),
                $entity->mutationToken()?->aggregateVersion,
                false,
                $replies,
                $replayed,
            );
        }
        if ($placeholder && $row['parent_uuid'] === null && $replies > 0) {
            return new CommentView($row['comment_uuid'], null, null, null, null, null, true, $replies);
        }
        return null;
    }

    private function visibleReplyCount(AuthorizationPrincipalInterface $viewer, EntityInterface $root, string $parent): int
    {
        $rows = $this->database->getConnection()->fetchAllAssociative(
            'SELECT author_id, lifecycle FROM engagement_comment_index WHERE root_type = ? AND root_uuid = ? AND parent_uuid = ?',
            [$root->getEntityTypeId(), $root->uuid(), $parent],
        );
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->visible($viewer, $root, $row) ? 1 : 0;
        }
        return $count;
    }

    private function assertParent(AuthorizationPrincipalInterface $actor, EntityInterface $root, ?string $parent): void
    {
        if ($parent === null) {
            return;
        }
        $row = $this->row($parent);
        $this->assertRowRoot($row, $root);
        if ($row['parent_uuid'] !== null || !$this->visible($actor, $root, $row)) {
            throw EngagementAccess::unavailable();
        }
    }

    private function likeState(AuthorizationPrincipalInterface $viewer, EntityInterface $root): LikeState
    {
        $rows = $this->database->getConnection()->fetchAllAssociative(
            'SELECT actor_id FROM engagement_like_identity WHERE root_type = ? AND root_uuid = ? AND reaction_type = ?',
            [$root->getEntityTypeId(), $root->uuid(), 'like'],
        );
        $count = 0;
        $liked = false;
        foreach ($rows as $row) {
            if ($this->access->canViewActor($viewer, (int) $row['actor_id'], $root)) {
                ++$count;
                $liked = $liked || ($viewer->isAuthenticated() && (string) $row['actor_id'] === (string) $viewer->id());
            }
        }
        return new LikeState($liked, $count);
    }

    private function generation(AuthorizationPrincipalInterface $viewer): string
    {
        $external = $this->access->policy->generation($viewer);
        if ($external === '') {
            throw new EngagementException('TEMPORARILY_UNAVAILABLE', 503, 'Current policy generation is unavailable.');
        }
        $internal = $this->database->getConnection()->fetchOne('SELECT visibility_generation FROM engagement_policy_state WHERE id = 1');
        return hash('sha256', $external . ':' . $internal);
    }
}
