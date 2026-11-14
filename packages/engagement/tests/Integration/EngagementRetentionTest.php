<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\Reaction;
use Waaseyaa\Engagement\Tests\Fixtures\ServiceEnvironment;
use Waaseyaa\Entity\EntityReadRuntime;

final class EngagementRetentionTest extends TestCase
{
    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        parent::tearDown();
    }

    public static function storageLayouts(): iterable
    {
        yield 'sql-blob' => [false];
        yield 'sql-column' => [true];
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function author_removal_records_actor_then_minimizes_metadata_after_thirty_days(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $parentKey = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $parent = $env->service->createComment($env->alice, $env->rootReference, 'parent body to erase', $parentKey);
        $replyKey = $env->service->issueCommentKey($env->bob, $env->rootReference, $parent->uuid);
        $env->service->createComment($env->bob, $env->rootReference, 'surviving reply', $replyKey, $parent->uuid);

        $env->service->removeComment($env->alice, $parent->uuid);

        $removed = $this->storedComment($env, $parent->uuid);
        self::assertSame('', $removed['body']);
        self::assertSame('removed', $removed['lifecycle']);
        self::assertSame(1, $removed['removal_actor_id']);
        self::assertSame('author', $removed['removal_kind']);
        self::assertSame($env->now, $removed['removed_at']);

        $env->now += 30 * 86400;
        $env->retention->sweep();

        $minimized = $this->storedComment($env, $parent->uuid);
        self::assertSame('', $minimized['body']);
        self::assertSame('purged', $minimized['lifecycle']);
        self::assertSame(0, $minimized['user_id']);
        self::assertNull($minimized['removal_actor_id']);
        self::assertNull($minimized['removal_kind']);
        self::assertNull($minimized['removed_at']);
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne(
            'SELECT author_id FROM engagement_comment_index WHERE comment_uuid = ?', [$parent->uuid],
        ));
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function root_deletion_queues_erasure_and_cleans_comment_body(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $comment = $env->service->createComment($env->alice, $env->rootReference, 'root-owned body', $key);

        $env->database->transactional(function () use ($env): void {
            // ServiceEnvironment does not boot the provider listener. Invoke its
            // queueing callback in the same canonical root-delete transaction.
            $env->retention->queueDeletion($env->root);
            $env->entities->getRepository('service_root')->delete($env->root);
        });

        self::assertSame(1, (int) $env->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_root_erasure WHERE root_type = ? AND root_uuid = ?',
            ['service_root', $env->root->uuid()],
        ));

        // One-row batch makes the erased entity inspectable before structural
        // cleanup removes its now-unneeded index row.
        $batch = $env->retention->runBatch(1);
        self::assertSame(1, $batch['processed']);
        $erased = $this->storedComment($env, $comment->uuid);
        self::assertSame('', $erased['body']);
        self::assertSame('purged', $erased['lifecycle']);
        self::assertSame(0, $erased['user_id']);
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function account_deletion_queues_erasure_drops_receipts_and_erases_likes(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $env->service->setLiked($env->alice, $env->rootReference, true);
        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $parent = $env->service->createComment($env->alice, $env->rootReference, 'account-owned body', $key);
        $replyKey = $env->service->issueCommentKey($env->bob, $env->rootReference, $parent->uuid);
        $env->service->createComment($env->bob, $env->rootReference, 'surviving structural reply', $replyKey, $parent->uuid);

        $quarantined = $env->entities->getRepository('comment')->create([
            'user_id' => 1,
            'target_type' => 'missing_root_type',
            'target_id' => 987654,
            'root_uuid' => '',
            'parent_comment_uuid' => null,
            'body' => 'unindexed quarantined payload',
            'status' => true,
            'lifecycle' => 'quarantined',
            'quarantined_at' => $env->now,
            'created_at' => $env->now,
        ]);
        $env->database->transactional(fn () => $env->scope->runMigration(
            fn (): int => $env->entities->getRepository('comment')->save($quarantined, false),
        ));
        $quarantinedUuid = $quarantined->uuid();
        $quarantinedNonLike = $env->entities->getRepository('reaction')->create([
            'user_id' => 1,
            'target_type' => 'missing_root_type',
            'target_id' => 987654,
            'reaction_type' => 'love',
            'root_uuid' => '',
            'lifecycle' => 'quarantined',
            'quarantined_at' => $env->now,
            'created_at' => $env->now,
        ]);
        $env->database->transactional(fn () => $env->scope->runMigration(
            fn (): int => $env->entities->getRepository('reaction')->save($quarantinedNonLike, false),
        ));
        $quarantinedNonLikeUuid = $quarantinedNonLike->uuid();
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_receipts WHERE actor_id = ?', [1],
        ));

        $env->database->transactional(function () use ($env): void {
            // Mirrors EngagementServiceProvider's user-deletion listener.
            $env->retention->queueDeletion($env->aliceEntity);
            $env->entities->getRepository('user')->delete($env->aliceEntity);
        });

        self::assertSame(1, (int) $env->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_actor_erasure WHERE actor_id = ?', [1],
        ));
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_receipts WHERE actor_id = ?', [1],
        ));
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM engagement_receipts WHERE actor_id = ?', [2],
        ));

        $env->retention->sweep();

        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_like_identity'));
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne("SELECT COUNT(*) FROM reaction WHERE reaction_type = 'like'"));
        self::assertSame(2, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_comment_index'));
        $minimized = $this->storedComment($env, $parent->uuid);
        self::assertSame('', $minimized['body']);
        self::assertSame('purged', $minimized['lifecycle']);
        self::assertSame(0, $minimized['user_id']);
        $quarantinedMatches = $env->database->transactional(fn () => $env->scope->runMigration(
            fn (): array => $env->entities->getRepository('comment')->findBy(['uuid' => $quarantinedUuid], null, 1),
        ));
        self::assertSame([], $quarantinedMatches, 'Unindexed quarantined data must be erased immediately for an erased account.');
        $nonLikeState = $env->database->transactional(fn (): array => $env->scope->runMigration(function () use ($env, $quarantinedNonLikeUuid): array {
            $matches = $env->entities->getRepository('reaction')->findBy(['uuid' => $quarantinedNonLikeUuid], null, 1);
            $reaction = $matches[0] ?? null;
            if (!$reaction instanceof Reaction) {
                return [];
            }
            return [(string) $reaction->get('reaction_type'), (string) $reaction->get('lifecycle'), (int) $reaction->get('user_id')];
        }));
        self::assertSame(['love', 'quarantined', 1], $nonLikeState, 'Account erasure must not expand into the legacy non-like reaction lifecycle.');
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_actor_erasure'));
    }

    /** @return array{body:string,lifecycle:string,user_id:int,removal_actor_id:?int,removal_kind:?string,removed_at:?int} */
    private function storedComment(ServiceEnvironment $env, string $uuid): array
    {
        return $env->database->transactional(fn (): array => $env->scope->runMigration(function () use ($env, $uuid): array {
            $matches = $env->entities->getRepository('comment')->findBy(['uuid' => $uuid], null, 1);
            $entity = $matches[0] ?? null;
            if (!$entity instanceof Comment) {
                self::fail(sprintf('Expected comment %s to remain inspectable.', $uuid));
            }
            return [
                'body' => (string) $entity->get('body'),
                'lifecycle' => (string) $entity->get('lifecycle'),
                'user_id' => (int) $entity->get('user_id'),
                'removal_actor_id' => $entity->get('removal_actor_id') === null ? null : (int) $entity->get('removal_actor_id'),
                'removal_kind' => $entity->get('removal_kind'),
                'removed_at' => $entity->get('removed_at') === null ? null : (int) $entity->get('removed_at'),
            ];
        }));
    }
}
