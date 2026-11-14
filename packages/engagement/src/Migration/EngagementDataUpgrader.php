<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Migration;

use Doctrine\DBAL\Connection;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\EngagementMutationScope;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** Resumable, bounded migration of legacy comments and like projections. */
final readonly class EngagementDataUpgrader
{
    private const ENTITY_TYPES = ['comment', 'reaction'];

    public function __construct(
        private DBALDatabase $database,
        private EntityTypeManagerInterface $entityTypes,
        private EngagementMutationScope $mutationScope,
    ) {}

    /**
     * Processes at most $limit source rows. Entity changes always use the
     * configured canonical repositories inside the trusted migration scope.
     *
     * @return array{scanned:int,migrated:int,quarantined:int,duplicate_likes_removed:int,done:bool,progress:array{comment_id:int,reaction_id:int},reasons:array<string,int>,ids:array<string,list<int>>}
     */
    public function runBatch(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Engagement upgrade batches must contain 1 to 200 rows.');
        }

        return $this->database->transactional(function () use ($limit): array {
            $connection = $this->database->getConnection();
            $locked = $connection->executeStatement(
                'UPDATE engagement_policy_state SET visibility_generation = visibility_generation WHERE id = 1',
            );
            if ($locked !== 1) {
                throw new \RuntimeException('Engagement policy writer state is unavailable.');
            }
            return $this->processBatch($connection, $limit);
        });
    }

    /** Inspect the already-installed progress schema without creating or altering it. */
    public static function schemaReady(DBALDatabase $database): bool
    {
        $schemaManager = $database->getConnection()->createSchemaManager();
        if (!$schemaManager->tablesExist(['engagement_upgrade_progress'])) {
            return false;
        }
        $columns = array_fill_keys(array_map(
            static fn($column): string => $column->getName(),
            $schemaManager->listTableColumns('engagement_upgrade_progress'),
        ), true);

        return isset($columns['id'], $columns['last_id'], $columns['complete']);
    }

    /**
     * Readiness is established only by an explicit completed CLI upgrade run.
     *
     * @api Read-only progress inspection for upgrade orchestration.
     */
    public function isReady(): bool
    {
        if (!self::schemaReady($this->database)) {
            return false;
        }
        foreach (self::ENTITY_TYPES as $entityType) {
            $complete = $this->database->getConnection()->fetchOne(
                'SELECT complete FROM engagement_upgrade_progress WHERE id = ?',
                [$entityType],
            );
            if ((int) $complete !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return array{scanned:int,migrated:int,quarantined:int,duplicate_likes_removed:int,done:bool,progress:array{comment_id:int,reaction_id:int},reasons:array<string,int>,ids:array<string,list<int>>} */
    private function processBatch(Connection $connection, int $limit): array
    {
        $progress = $this->ensureProgressRows($connection);
        if ($this->progressComplete($progress)) {
            return $this->result(0, 0, 0, 0, true, $progress, [], []);
        }

        $scanned = $migrated = $quarantined = $duplicates = 0;
        $reasons = [];
        $ids = ['quarantined_comments' => [], 'quarantined_reactions' => [], 'duplicate_likes_removed' => []];

        foreach (self::ENTITY_TYPES as $entityType) {
            $lastId = $progress[$entityType]['last_id'];
            if ($progress[$entityType]['complete'] === 1 || $scanned >= $limit) {
                continue;
            }
            $idColumn = $entityType === 'comment' ? 'cid' : 'rid';
            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT %s AS id FROM %s WHERE %s > ? ORDER BY %s ASC LIMIT %d', $idColumn, $entityType, $idColumn, $idColumn, $limit - $scanned),
                [$lastId],
            );
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($entityType === 'comment') {
                    $counts = $this->migrateComment($id);
                    $migrated += $counts['migrated'];
                    $quarantined += $counts['quarantined'];
                    if ($counts['reason'] !== null) {
                        $reasons[$counts['reason']] = ($reasons[$counts['reason']] ?? 0) + 1;
                        $ids['quarantined_comments'][] = $id;
                    }
                } else {
                    $reactionResult = $this->migrateReaction($id);
                    if ($reactionResult['duplicate_removed']) {
                        ++$duplicates;
                        $ids['duplicate_likes_removed'][] = $id;
                    }
                    if ($reactionResult['quarantined']) {
                        ++$quarantined;
                        $ids['quarantined_reactions'][] = $id;
                        $reasons['invalid_reaction_integrity'] = ($reasons['invalid_reaction_integrity'] ?? 0) + 1;
                    }
                }
                ++$scanned;
                $lastId = $id;
            }
            $hasMore = $connection->fetchOne(
                sprintf('SELECT 1 FROM %s WHERE %s > ? LIMIT 1', $entityType, $idColumn),
                [$lastId],
            ) !== false;
            $progress[$entityType] = ['id' => $entityType, 'last_id' => $lastId, 'complete' => $hasMore ? 0 : 1];
            $connection->update('engagement_upgrade_progress', [
                'last_id' => $lastId,
                'complete' => $hasMore ? 0 : 1,
            ], ['id' => $entityType]);
        }

        $done = $this->progressComplete($progress);
        return $this->result($scanned, $migrated, $quarantined, $duplicates, $done, $progress, $reasons, $ids);
    }

    /** @return array{migrated:int,quarantined:int,reason:?string} */
    private function migrateComment(int $id): array
    {
        return $this->mutationScope->runMigration(function () use ($id): array {
            $repository = $this->entityTypes->getRepository('comment');
            $comment = $repository->find($id);
            if ($comment === null) {
                return ['migrated' => 0, 'quarantined' => 0, 'reason' => null];
            }
            $rootType = (string) $comment->get('target_type');
            $rootId = (int) $comment->get('target_id');
            $authorId = (int) $comment->get('user_id');
            $reason = null;
            $rootUuid = '';

            if ($rootType === '' || $rootId < 1 || in_array($rootType, ['comment', 'reaction', 'follow'], true)
                || !$this->entityTypes->hasDefinition($rootType)) {
                $reason = 'unknown_root_type_or_id';
            } else {
                $root = $this->entityTypes->getRepository($rootType)->find($rootId);
                if ($root === null) {
                    $reason = 'missing_root';
                } else {
                    $rootUuid = $root->uuid();
                }
            }
            if ($reason === null && ($authorId < 1 || !$this->entityTypes->hasDefinition('user')
                || $this->entityTypes->getRepository('user')->find($authorId) === null)) {
                $reason = 'invalid_author';
            }

            if ($reason !== null) {
                $comment->set('root_uuid', '');
                $comment->set('lifecycle', 'quarantined');
                $comment->set('quarantined_at', time());
                $comment->set('parent_comment_uuid', null);
                $repository->save($comment, false);
                return ['migrated' => 0, 'quarantined' => 1, 'reason' => $reason];
            }

            $comment->set('root_uuid', $rootUuid);
            $comment->set('parent_comment_uuid', null);
            $comment->set('edited_at', null);
            $comment->set('quarantined_at', null);
            // Restriction is not removal. Preserve legacy unpublished text in a
            // non-serving lifecycle until an explicit moderation/erasure action.
            $comment->set('lifecycle', $comment->get('status') === true ? 'active' : 'unpublished');
            $comment->set('removed_at', null);
            $repository->save($comment, false);

            $this->upsertCommentIndex(
                $comment,
                $rootType,
                $rootId,
                $rootUuid,
                $authorId,
                (string) $comment->get('lifecycle'),
                $comment->get('removed_at') === null ? null : (int) $comment->get('removed_at'),
            );
            return ['migrated' => 1, 'quarantined' => 0, 'reason' => null];
        });
    }

    /** @return array{duplicate_removed:bool,quarantined:bool} */
    private function migrateReaction(int $id): array
    {
        return $this->mutationScope->runMigration(function () use ($id): array {
            $repository = $this->entityTypes->getRepository('reaction');
            $reaction = $repository->find($id);
            if ($reaction === null) {
                return ['duplicate_removed' => false, 'quarantined' => false];
            }
            $isLike = (string) $reaction->get('reaction_type') === 'like';
            if (!$isLike) {
                return ['duplicate_removed' => false, 'quarantined' => false];
            }
            $rootType = (string) $reaction->get('target_type');
            $rootId = (int) $reaction->get('target_id');
            $actorId = (int) $reaction->get('user_id');
            $root = $rootType !== '' && $rootId > 0 && !in_array($rootType, ['comment', 'reaction', 'follow'], true)
                && $this->entityTypes->hasDefinition($rootType)
                ? $this->entityTypes->getRepository($rootType)->find($rootId)
                : null;
            $actor = $actorId > 0 && $this->entityTypes->hasDefinition('user')
                ? $this->entityTypes->getRepository('user')->find($actorId)
                : null;
            $rootUuid = $root?->uuid() ?? '';
            $connection = $this->database->getConnection();

            if ($root === null || $actor === null) {
                // Keep existing non-like rows untouched. Invalid likes are
                // quarantined without fabricating a root or actor identity.
                $reaction->set('root_uuid', '');
                $reaction->set('lifecycle', 'quarantined');
                $reaction->set('quarantined_at', time());
                $repository->save($reaction, false);
                return ['duplicate_removed' => false, 'quarantined' => true];
            }


            $existing = $connection->fetchOne(
                'SELECT reaction_uuid FROM engagement_like_identity WHERE actor_id = ? AND root_type = ? AND root_uuid = ? AND reaction_type = ?',
                [$actorId, $rootType, $rootUuid, 'like'],
            );
            if ($existing !== false) {
                $survivor = $repository->findBy(['uuid' => (string) $existing], null, 1)[0] ?? null;
                if ($survivor === null) {
                    throw new \RuntimeException('Like identity references a missing reaction during upgrade.');
                }
                $currentCreated = (int) ($reaction->get('created_at') ?? PHP_INT_MAX);
                $survivorCreated = (int) ($survivor->get('created_at') ?? PHP_INT_MAX);
                $currentId = (int) $reaction->id();
                $survivorId = (int) $survivor->id();
                $replaceSurvivor = $currentCreated < $survivorCreated
                    || ($currentCreated === $survivorCreated && $currentId < $survivorId);
                if ($replaceSurvivor) {
                    $reaction->set('root_uuid', $rootUuid);
                    $reaction->set('lifecycle', 'active');
                    $reaction->set('quarantined_at', null);
                    $repository->save($reaction, false);
                    $connection->update('engagement_like_identity', [
                        'reaction_uuid' => $reaction->uuid(),
                        'root_id' => $rootId,
                    ], [
                        'actor_id' => $actorId,
                        'root_type' => $rootType,
                        'root_uuid' => $rootUuid,
                        'reaction_type' => 'like',
                    ]);
                    $repository->delete($survivor);
                } else {
                    // A genuine legacy row has no root UUID yet. Resolve its
                    // identity before deletion so the mandatory guard and
                    // canonical delete callbacks see the same valid target.
                    $reaction->set('root_uuid', $rootUuid);
                    $reaction->set('lifecycle', 'active');
                    $reaction->set('quarantined_at', null);
                    $repository->save($reaction, false);
                    $fresh = $repository->find((string) $reaction->id());
                    if ($fresh === null) {
                        throw new \RuntimeException('Duplicate like could not be reloaded for canonical deletion.');
                    }
                    $repository->delete($fresh);
                }
                return ['duplicate_removed' => true, 'quarantined' => false];
            }

            $reaction->set('root_uuid', $rootUuid);
            $reaction->set('lifecycle', 'active');
            $reaction->set('quarantined_at', null);
            $repository->save($reaction, false);
            $connection->insert('engagement_like_identity', [
                'actor_id' => $actorId,
                'root_type' => $rootType,
                'root_id' => $rootId,
                'root_uuid' => $rootUuid,
                'reaction_type' => 'like',
                'reaction_uuid' => $reaction->uuid(),
            ]);
            return ['duplicate_removed' => false, 'quarantined' => false];
        });
    }

    private function upsertCommentIndex(EntityInterface $comment, string $rootType, int $rootId, string $rootUuid, int $authorId, string $lifecycle, ?int $removedAt): void
    {
        $connection = $this->database->getConnection();
        $values = [
            'comment_uuid' => $comment->uuid(),
            'comment_id' => (int) $comment->id(),
            'root_type' => $rootType,
            'root_id' => $rootId,
            'root_uuid' => $rootUuid,
            'parent_uuid' => null,
            'author_id' => $authorId,
            'created_at' => (int) ($comment->get('created_at') ?? 0),
            'lifecycle' => $lifecycle,
            'removed_at' => $removedAt,
        ];
        $found = $connection->fetchOne('SELECT comment_uuid FROM engagement_comment_index WHERE comment_uuid = ?', [$comment->uuid()]);
        if ($found === false) {
            $connection->insert('engagement_comment_index', $values);
        } else {
            $connection->update('engagement_comment_index', $values, ['comment_uuid' => $comment->uuid()]);
        }
    }

    /** @return array<string,array{id:string,last_id:int,complete:int}> */
    private function ensureProgressRows(Connection $connection): array
    {
        $rows = [];
        foreach (self::ENTITY_TYPES as $entityType) {
            if ($connection->fetchOne('SELECT 1 FROM engagement_upgrade_progress WHERE id = ?', [$entityType]) === false) {
                $connection->insert('engagement_upgrade_progress', [
                    'id' => $entityType,
                    'last_id' => 0,
                    'complete' => 0,
                ]);
            }
            $row = $connection->fetchAssociative(
                'SELECT id, last_id, complete FROM engagement_upgrade_progress WHERE id = ?',
                [$entityType],
            );
            if ($row === false) {
                throw new \RuntimeException(sprintf('Engagement upgrade progress row "%s" is unavailable.', $entityType));
            }
            $rows[$entityType] = $row;
        }
        return $rows;
    }

    /** @param array<string,array{id:string,last_id:int|string,complete:int|string}> $progress */
    private function progressComplete(array $progress): bool
    {
        foreach (self::ENTITY_TYPES as $entityType) {
            if ((int) $progress[$entityType]['complete'] !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $progress @param array<string,int> $reasons @param array<string,list<int>> $ids
     * @return array{scanned:int,migrated:int,quarantined:int,duplicate_likes_removed:int,done:bool,progress:array{comment_id:int,reaction_id:int},reasons:array<string,int>,ids:array<string,list<int>>}
     */
    private function result(int $scanned, int $migrated, int $quarantined, int $duplicates, bool $done, array $progress, array $reasons, array $ids): array
    {
        return [
            'scanned' => $scanned,
            'migrated' => $migrated,
            'quarantined' => $quarantined,
            'duplicate_likes_removed' => $duplicates,
            'done' => $done,
            'progress' => [
                'comment_id' => (int) $progress['comment']['last_id'],
                'reaction_id' => (int) $progress['reaction']['last_id'],
            ],
            'reasons' => $reasons,
            'ids' => $ids,
        ];
    }
}
