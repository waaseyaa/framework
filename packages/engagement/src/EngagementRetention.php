<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Engagement\Migration\EngagementDataUpgrader;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/** @internal Canonical maintenance for erased roots, accounts and expired metadata. */
final class EngagementRetention
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly DBALDatabase $database,
        private readonly EntityTypeManagerInterface $entities,
        private readonly EngagementMutationScope $scope,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Called by the canonical PRE_DELETE event inside the deleting transaction. */
    public function queueDeletion(EntityInterface $entity): void
    {
        if (in_array($entity->getEntityTypeId(), ['reaction', 'comment', 'follow'], true)
            || !$this->database->schema()->tableExists('engagement_root_erasure')
        ) {
            return;
        }
        if (!$this->database->getConnection()->isTransactionActive()) {
            throw new \LogicException('Engagement erasure admission requires the deleting transaction.');
        }
        $connection = $this->database->getConnection();
        $key = ['root_type' => $entity->getEntityTypeId(), 'root_uuid' => $entity->uuid()];
        if ($connection->fetchOne('SELECT 1 FROM engagement_root_erasure WHERE root_type = ? AND root_uuid = ?', array_values($key)) === false) {
            $connection->insert('engagement_root_erasure', [...$key, 'requested_at' => ($this->clock)()]);
        }
        if ($entity->getEntityTypeId() === 'user' && (int) $entity->id() > 0) {
            $uid = (int) $entity->id();
            if ($connection->fetchOne('SELECT 1 FROM engagement_actor_erasure WHERE actor_id = ?', [$uid]) === false) {
                $connection->insert('engagement_actor_erasure', ['actor_id' => $uid, 'requested_at' => ($this->clock)()]);
            }
            $connection->delete('engagement_receipts', ['actor_id' => $uid]);
        }
    }

    public function ready(): bool
    {
        if (!$this->database->schema()->tableExists('engagement_upgrade_progress')
            || !$this->database->schema()->tableExists('engagement_root_erasure')
        ) {
            return false;
        }
        return EngagementDataUpgrader::isReady($this->database);
    }

    /** Drain due work in independently committed batches so other writers can proceed. */
    public function sweep(): int
    {
        $total = 0;
        do {
            $result = $this->runBatch();
            $total += $result['processed'];
        } while (!$result['done']);
        return $total;
    }

    /** @return array{processed:int,done:bool} */
    public function runBatch(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Retention batches contain 1 to 200 entries.');
        }
        return $this->database->transactional(function () use ($limit): array {
            $this->database->query('UPDATE engagement_policy_state SET visibility_generation = visibility_generation WHERE id = 1');
            return $this->scope->runMigration(function () use ($limit): array {
                $connection = $this->database->getConnection();
                $processed = 0;
                $visibilityChanged = false;
                $actor = $connection->fetchAssociative('SELECT * FROM engagement_actor_erasure ORDER BY requested_at, actor_id LIMIT 1');
                $root = $connection->fetchAssociative('SELECT * FROM engagement_root_erasure ORDER BY requested_at, root_type, root_uuid LIMIT 1');
                if ($actor !== false || $root !== false) {
                    $where = $actor !== false ? 'author_id = ?' : 'root_type = ? AND root_uuid = ?';
                    $bindings = $actor !== false ? [(int) $actor['actor_id']] : [$root['root_type'], $root['root_uuid']];
                    $rows = $connection->fetchAllAssociative(
                        'SELECT * FROM engagement_comment_index WHERE (' . $where . ") AND lifecycle != 'purged' ORDER BY comment_id LIMIT " . $limit,
                        $bindings,
                    );
                    foreach ($rows as $row) {
                        $entity = $this->entities->getRepository('comment')->find((int) $row['comment_id']);
                        if ($entity instanceof Comment) {
                            $this->eraseComment($entity, $row);
                            $visibilityChanged = true;
                        } else {
                            $connection->delete('engagement_comment_index', ['comment_uuid' => $row['comment_uuid']]);
                        }
                        ++$processed;
                    }
                    if ($processed < $limit) {
                        $where = $actor !== false ? 'actor_id = ?' : 'root_type = ? AND root_uuid = ?';
                        $likes = $connection->fetchAllAssociative('SELECT reaction_uuid FROM engagement_like_identity WHERE ' . $where . ' LIMIT ' . ($limit - $processed), $bindings);
                        foreach ($likes as $like) {
                            $repository = $this->entities->getRepository('reaction');
                            $matches = $repository->findBy(['uuid' => $like['reaction_uuid']]);
                            if (isset($matches[0])) {
                                $repository->delete($matches[0]);
                            }
                            $connection->delete('engagement_like_identity', ['reaction_uuid' => $like['reaction_uuid']]);
                            $visibilityChanged = true;
                            ++$processed;
                        }
                    }
                    $quarantinedActorRows = false;
                    if ($actor !== false) {
                        foreach (['comment', 'reaction'] as $type) {
                            $query = $this->entities->getRepository($type)->getQuery()->accessCheck(false)
                                ->condition('lifecycle', 'quarantined')->condition('user_id', (int) $actor['actor_id']);
                            if ($type === 'reaction') {
                                $query->condition('reaction_type', 'like');
                            }
                            $quarantinedActorRows = $quarantinedActorRows || $query->range(0, 1)->execute() !== [];
                        }
                    }
                    if ($processed === 0 && !$quarantinedActorRows) {
                        if ($actor !== false) {
                            $connection->delete('engagement_actor_erasure', ['actor_id' => $actor['actor_id']]);
                        } else {
                            $connection->delete('engagement_root_erasure', ['root_type' => $root['root_type'], 'root_uuid' => $root['root_uuid']]);
                        }
                        ++$processed;
                    }
                }
                if ($processed < $limit) {
                    $rows = $connection->fetchAllAssociative(
                        "SELECT * FROM engagement_comment_index WHERE lifecycle = 'removed' AND removed_at <= ? ORDER BY removed_at, comment_id LIMIT " . ($limit - $processed),
                        [($this->clock)() - 30 * 86400],
                    );
                    foreach ($rows as $row) {
                        $entity = $this->entities->getRepository('comment')->find((int) $row['comment_id']);
                        if ($entity instanceof Comment) {
                            $this->eraseComment($entity, $row);
                            $visibilityChanged = true;
                        } else {
                            $connection->delete('engagement_comment_index', ['comment_uuid' => $row['comment_uuid']]);
                        }
                        ++$processed;
                    }
                }
                foreach (['comment', 'reaction'] as $type) {
                    if ($processed === $limit) {
                        break;
                    }
                    $repository = $this->entities->getRepository($type);
                    $query = $repository->getQuery()->accessCheck(false)
                        ->condition('lifecycle', 'quarantined');
                    if ($actor !== false) {
                        $query->condition('user_id', (int) $actor['actor_id']);
                    } else {
                        $query->condition('quarantined_at', ($this->clock)() - 30 * 86400, '<=');
                    }
                    if ($type === 'reaction') {
                        $query->condition('reaction_type', 'like');
                    }
                    $ids = $query->range(0, $limit - $processed)->execute();
                    foreach ($repository->findMany($ids) as $entity) {
                        if ($entity instanceof Comment) {
                            $entity->set('body', '')->set('status', false);
                        }
                        $entity->set('user_id', 0)->set('lifecycle', 'purged')->set('quarantined_at', null);
                        $repository->save($entity, false);
                        $fresh = $repository->find((string) $entity->id());
                        if ($fresh !== null) {
                            $repository->delete($fresh);
                        }
                        ++$processed;
                        $visibilityChanged = true;
                    }
                }
                foreach (['engagement_receipts' => 'key_digest', 'engagement_rate_limits' => 'limiter_key'] as $table => $key) {
                    if ($processed === $limit) {
                        break;
                    }
                    $rows = $connection->fetchAllAssociative('SELECT ' . $key . ' FROM ' . $table . ' WHERE expires_at <= ? LIMIT ' . ($limit - $processed), [($this->clock)()]);
                    foreach ($rows as $row) {
                        $connection->delete($table, [$key => $row[$key]]);
                        ++$processed;
                    }
                }
                if ($processed < $limit) {
                    $rows = $connection->fetchAllAssociative(
                        "SELECT p.comment_uuid, p.comment_id FROM engagement_comment_index p WHERE p.lifecycle = 'purged' AND NOT EXISTS (SELECT 1 FROM engagement_comment_index r WHERE r.parent_uuid = p.comment_uuid) LIMIT " . ($limit - $processed),
                    );
                    foreach ($rows as $row) {
                        $repository = $this->entities->getRepository('comment');
                        $entity = $repository->find((int) $row['comment_id']);
                        if ($entity !== null) {
                            $repository->delete($entity);
                        }
                        $connection->delete('engagement_comment_index', ['comment_uuid' => $row['comment_uuid']]);
                        ++$processed;
                        $visibilityChanged = true;
                    }
                }
                if ($visibilityChanged) {
                    $this->database->query('UPDATE engagement_policy_state SET visibility_generation = visibility_generation + 1 WHERE id = 1');
                }
                return ['processed' => $processed, 'done' => $processed === 0];
            });
        });
    }

    /** @param array<string,mixed> $row */
    private function eraseComment(Comment $entity, array $row): void
    {
        $entity->set('body', '')->set('status', false)->set('user_id', 0)->set('lifecycle', 'purged')
            ->set('removal_actor_id', null)->set('removal_kind', null)->set('edited_at', null)->set('removed_at', null)->set('quarantined_at', null);
        $this->entities->getRepository('comment')->save($entity, false);
        $this->database->getConnection()->update('engagement_comment_index', [
            'author_id' => 0, 'lifecycle' => 'purged', 'removed_at' => null,
        ], ['comment_uuid' => $row['comment_uuid']]);
    }
}
