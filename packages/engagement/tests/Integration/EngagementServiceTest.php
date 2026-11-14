<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\Reaction;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\EngagementServiceProvider;
use Waaseyaa\Engagement\Command\EngagementUpgradeCommand;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Engagement\Tests\Fixtures\ServiceEnvironment;

final class EngagementServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        \Waaseyaa\Entity\EntityReadRuntime::installGuard(null);
        parent::tearDown();
    }

    public static function storageLayouts(): iterable
    {
        yield 'sql-blob' => [false];
        yield 'sql-column' => [true];
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function creates_a_comment_and_applies_idempotent_like_desired_state(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);

        $unliked = $env->service->setLiked($env->alice, $env->rootReference, false);
        self::assertFalse($unliked->liked);
        self::assertSame(0, $unliked->count);

        $firstLike = $env->service->setLiked($env->alice, $env->rootReference, true);
        $retryLike = $env->service->setLiked($env->alice, $env->rootReference, true);
        self::assertTrue($firstLike->liked);
        self::assertTrue($retryLike->liked);
        self::assertSame(1, $firstLike->count);
        self::assertSame(1, $retryLike->count);
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_like_identity'));

        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $comment = $env->service->createComment($env->alice, $env->rootReference, "  Hello\r\nworld  ", $key);
        self::assertSame("Hello\nworld", $comment->text);
        self::assertSame(1, $comment->revision);
        self::assertFalse($comment->placeholder);

        $wire = base64_decode(strtr($key, '-_', '+/') . str_repeat('=', (4 - strlen($key) % 4) % 4), true);
        self::assertNotFalse($wire);
        $alias = rtrim(strtr(base64_encode("\n" . $wire . "\n"), '+/', '-_'), '=');
        self::assertSame($env->tokens->readSubmission($key), $env->tokens->readSubmission($alias));
        $aliasReplay = $env->service->createComment($env->alice, $env->rootReference, "Hello\nworld", $alias);
        self::assertSame($comment->uuid, $aliasReplay->uuid);
        self::assertTrue($aliasReplay->replayed);
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_comment_index'));

        $replayed = $env->service->createComment($env->alice, $env->rootReference, "Hello\nworld", $key);
        self::assertSame($comment->uuid, $replayed->uuid);
        self::assertTrue($replayed->replayed);
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_comment_index'));
        try {
            $env->service->createComment($env->alice, $env->rootReference, 'different payload', $key);
            self::fail('A submission key cannot be reused for another payload.');
        } catch (EngagementException $failure) {
            self::assertSame('SUBMISSION_CONFLICT', $failure->errorCode);
        }

        $summary = $env->service->summaries($env->bob, [$env->rootReference])[0];
        self::assertSame(1, $summary['likeCount']);
        self::assertFalse($summary['liked']);
        self::assertSame(1, $summary['commentCount']);
        self::assertSame(1, $summary['topLevelCount']);

        $env->now += \Waaseyaa\Engagement\EngagementTokens::SUBMISSION_TTL;
        try {
            $env->service->createComment($env->alice, $env->rootReference, 'expired key', $key);
            self::fail('Expired submission keys must be refused.');
        } catch (EngagementException $failure) {
            self::assertSame('SUBMISSION_EXPIRED', $failure->errorCode);
        }
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function replies_are_one_level_edits_are_revision_checked_and_removal_erases_text(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $parentKey = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $parent = $env->service->createComment($env->alice, $env->rootReference, 'parent text', $parentKey);
        $replyKey = $env->service->issueCommentKey($env->bob, $env->rootReference, $parent->uuid);
        $reply = $env->service->createComment($env->bob, $env->rootReference, 'reply text', $replyKey, $parent->uuid);

        self::assertSame($reply->uuid, $env->service->replies($env->bob, $env->rootReference, $parent->uuid)->items[0]->uuid);
        try {
            $nestedKey = $env->service->issueCommentKey($env->alice, $env->rootReference, $reply->uuid);
            self::fail('Replies to replies must be rejected.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }
        $forgedNestedKey = $env->tokens->issueSubmission('1', 'service_root', $env->root->uuid(), $reply->uuid);
        try {
            $env->service->createComment($env->alice, $env->rootReference, 'too deep', $forgedNestedKey, $reply->uuid);
            self::fail('Creation itself must reject a reply-to-reply parent.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }

        $edited = $env->service->editComment($env->alice, $parent->uuid, 'parent revised', $parent->revision);
        self::assertSame('parent revised', $edited->text);
        self::assertSame($parent->revision + 1, $edited->revision);
        $noOp = $env->service->editComment($env->alice, $parent->uuid, 'parent revised', $edited->revision);
        self::assertSame($edited->revision, $noOp->revision);
        try {
            $env->service->editComment($env->alice, $parent->uuid, 'stale overwrite', $parent->revision);
            self::fail('Stale edits must conflict.');
        } catch (EngagementException $failure) {
            self::assertSame('REVISION_CONFLICT', $failure->errorCode);
        }

        $env->service->removeComment($env->alice, $parent->uuid);
        $page = $env->service->comments($env->bob, $env->rootReference);
        self::assertCount(1, $page->items);
        self::assertTrue($page->items[0]->placeholder);
        self::assertNull($page->items[0]->authorId);
        self::assertNull($page->items[0]->text);
        self::assertSame(1, $page->items[0]->replyCount);
        $erased = $env->database->transactional(fn () => $env->scope->runMigration(function () use ($env, $parent): array {
            $stored = $env->entities->getRepository('comment')->findBy(['uuid' => $parent->uuid])[0];
            return [$stored->get('body'), $stored->get('lifecycle')];
        }));
        self::assertSame(['', 'removed'], $erased);
        self::assertSame(1, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_comment_index WHERE lifecycle = ?', ['removed']));
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function blocks_hide_authors_without_exposing_comment_or_count_and_hidden_root_denies_direct_reads(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $parent = $env->service->createComment($env->alice, $env->rootReference, 'private blocked text', $key);
        $replyKey = $env->service->issueCommentKey($env->bob, $env->rootReference, $parent->uuid);
        $reply = $env->service->createComment($env->bob, $env->rootReference, 'visible reply', $replyKey, $parent->uuid);
        $env->policy->setAliceBlocked(true);

        $page = $env->service->comments($env->bob, $env->rootReference);
        self::assertCount(1, $page->items);
        self::assertTrue($page->items[0]->placeholder);
        self::assertNull($page->items[0]->authorId);
        self::assertNull($page->items[0]->text);
        self::assertSame(1, $page->items[0]->replyCount);
        $replyPage = $env->service->replies($env->bob, $env->rootReference, $parent->uuid);
        self::assertSame([$reply->uuid], array_map(static fn ($item): string => $item->uuid, $replyPage->items));
        self::assertSame($reply->uuid, $env->service->getComment($env->bob, $reply->uuid)->uuid);
        $summary = $env->service->summaries($env->bob, [$env->rootReference])[0];
        self::assertSame(1, $summary['commentCount']);
        self::assertSame(0, $summary['topLevelCount']);
        $directPlaceholder = $env->service->getComment($env->bob, $parent->uuid);
        self::assertTrue($directPlaceholder->placeholder);
        self::assertNull($directPlaceholder->authorId);
        self::assertNull($directPlaceholder->text);

        $env->policy->setRootVisible(false);
        try {
            $env->service->summaries($env->bob, [$env->rootReference]);
            self::fail('Hidden roots must deny derived reads.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }
        try {
            $env->service->createComment($env->alice, $env->rootReference, 'private blocked text', $key);
            self::fail('A retry must recheck a now-hidden root before returning the prior result.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }
        try {
            $env->service->getComment($env->bob, $parent->uuid);
            self::fail('A hidden root must hide direct comment reads.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function suspended_and_anonymous_principals_cannot_mutate_or_spoof_authors_through_repository(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $env->policy->setEligible(false);
        try {
            $env->service->setLiked($env->alice, $env->rootReference, true);
            self::fail('Suspended actors cannot interact.');
        } catch (EngagementException $failure) {
            self::assertSame('ACTION_FORBIDDEN', $failure->errorCode);
        }
        $env->policy->setEligible(true);

        $anonymous = new AuthorizationPrincipal(0, false, [], [], 'anonymous-service-test');
        $spoof = new Comment([
            'user_id' => 2,
            'target_type' => 'service_root',
            'target_id' => (int) $env->root->id(),
            'body' => 'spoofed author',
            'root_uuid' => $env->root->uuid(),
            'created_at' => $env->now,
        ]);
        try {
            $env->database->transactional(fn () => $env->scope->run($anonymous, 'comment', $spoof,
                fn () => $env->entities->getRepository('comment')->save($spoof, false)));
            self::fail('A repository mutation bound to an anonymous principal cannot claim another author.');
        } catch (EngagementException $failure) {
            self::assertSame('AUTH_REQUIRED', $failure->errorCode);
        }
        try {
            $env->database->transactional(fn () => $env->scope->run($env->alice, 'comment', $spoof,
                fn () => $env->entities->getRepository('comment')->save($spoof, false)));
            self::fail('An authenticated principal cannot assign a different repository author.');
        } catch (EngagementException $failure) {
            self::assertSame('ACTION_FORBIDDEN', $failure->errorCode);
        }
        $unscoped = new Comment([
            'user_id' => 1,
            'target_type' => 'service_root',
            'target_id' => (int) $env->root->id(),
            'body' => 'unscoped write',
            'root_uuid' => $env->root->uuid(),
            'created_at' => $env->now,
        ]);
        try {
            $env->database->transactional(fn () => $env->entities->getRepository('comment')->save($unscoped, false));
            self::fail('Repository writes without an engagement mutation scope must be refused.');
        } catch (EngagementException $failure) {
            self::assertSame('ACTION_FORBIDDEN', $failure->errorCode);
        }
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM comment'));
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function index_failure_rolls_back_comment_and_replay_receipt_together(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $env->database->getConnection()->executeStatement(<<<'SQL'
            CREATE TRIGGER engagement_service_test_abort_index BEFORE INSERT ON engagement_comment_index
            BEGIN SELECT RAISE(ABORT, 'intentional service index failure'); END
            SQL);
        try {
            $env->service->createComment($env->alice, $env->rootReference, 'must roll back', $key);
            self::fail('An index write failure must not return a successful comment.');
        } catch (EngagementException $failure) {
            self::assertSame('TEMPORARILY_UNAVAILABLE', $failure->errorCode);
        } finally {
            $env->database->getConnection()->executeStatement('DROP TRIGGER IF EXISTS engagement_service_test_abort_index');
        }

        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM comment'));
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_comment_index'));
        self::assertSame(0, (int) $env->database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_receipts'));
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function pagination_keeps_equal_time_order_and_rejects_changed_policy_generation(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $created = [];
        foreach (['one', 'two', 'three'] as $text) {
            $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
            $created[] = $env->service->createComment($env->alice, $env->rootReference, $text, $key)->uuid;
        }
        self::assertSame(1_800_000_000, $env->service->comments($env->bob, $env->rootReference, limit: 1)->items[0]->createdAt);
        $first = $env->service->comments($env->bob, $env->rootReference, limit: 1);
        self::assertTrue($first->hasMore);
        self::assertNotNull($first->nextCursor);
        $laterKey = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $later = $env->service->createComment($env->alice, $env->rootReference, 'after high water', $laterKey);
        $second = $env->service->comments($env->bob, $env->rootReference, $first->nextCursor, 1);
        self::assertSame([$created[0], $created[1]], [$first->items[0]->uuid, $second->items[0]->uuid]);
        $third = $env->service->comments($env->bob, $env->rootReference, $second->nextCursor, 1);
        self::assertSame($created[2], $third->items[0]->uuid);
        self::assertFalse($third->hasMore);
        self::assertNull($third->nextCursor);
        self::assertNotSame($later->uuid, $third->items[0]->uuid, 'A continuation cannot cross its captured high-water mark.');
        $env->policy->setAliceBlocked(true);
        try {
            $env->service->comments($env->bob, $env->rootReference, $first->nextCursor, 1);
            self::fail('A cursor must become stale after a policy generation change.');
        } catch (EngagementException $failure) {
            self::assertSame('CURSOR_STALE', $failure->errorCode);
        }
        $env->policy->setAliceBlocked(false);
        self::assertSame([$created[0]], array_map(static fn ($item): string => $item->uuid, $env->service->comments($env->bob, $env->rootReference, limit: 1)->items));
        $expiringPage = $env->service->comments($env->bob, $env->rootReference, limit: 1);
        $env->now += \Waaseyaa\Engagement\EngagementTokens::CURSOR_TTL;
        try {
            $env->service->comments($env->bob, $env->rootReference, $expiringPage->nextCursor, 1);
            self::fail('Discussion cursors expire at their configured lifetime.');
        } catch (EngagementException $failure) {
            self::assertSame('CURSOR_EXPIRED', $failure->errorCode);
        }
        foreach ([0, 51] as $invalidLimit) {
            try {
                $env->service->comments($env->bob, $env->rootReference, limit: $invalidLimit);
                self::fail('Discussion page limits are bounded.');
            } catch (EngagementException $failure) {
                self::assertSame('VALIDATION_FAILED', $failure->errorCode);
            }
        }
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function anonymous_read_and_cross_root_reply_policy_use_resolved_root_identity(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $key = $env->service->issueCommentKey($env->alice, $env->rootReference);
        $parent = $env->service->createComment($env->alice, $env->rootReference, 'root one', $key);
        $anonymous = new AuthorizationPrincipal(0, false, [], [], 'anonymous-reader');
        self::assertSame($parent->uuid, $env->service->comments($anonymous, $env->rootReference)->items[0]->uuid);
        self::assertSame(1, $env->service->summaries($anonymous, [$env->rootReference])[0]['commentCount']);

        $crossRootKey = $env->tokens->issueSubmission('2', 'service_root', $env->secondRoot->uuid(), $parent->uuid);
        try {
            $env->service->createComment($env->bob, $env->secondRootReference, 'cross-root reply', $crossRootKey, $parent->uuid);
            self::fail('Reply parents must belong to the same immutable root.');
        } catch (EngagementException $failure) {
            self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
        }
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function active_but_unpublished_malformed_comments_are_denied_by_entity_field_and_service_reads(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $malformed = new Comment([
            'user_id' => 1,
            'target_type' => 'service_root',
            'target_id' => (int) $env->root->id(),
            'body' => 'malformed should stay private',
            'root_uuid' => $env->root->uuid(),
            'lifecycle' => 'active',
            'status' => false,
            'created_at' => $env->now,
        ]);
        $env->database->transactional(fn () => $env->scope->runMigration(function () use ($env, $malformed): void {
            $repository = $env->entities->getRepository('comment');
            $repository->save($malformed, false);
            $env->database->getConnection()->insert('engagement_comment_index', [
                'comment_uuid' => $malformed->uuid(),
                'comment_id' => (int) $malformed->id(),
                'root_type' => 'service_root',
                'root_id' => (int) $env->root->id(),
                'root_uuid' => $env->root->uuid(),
                'parent_uuid' => null,
                'author_id' => 1,
                'created_at' => $env->now,
                'lifecycle' => 'active',
                'removed_at' => null,
            ]);
        }));

        $entity = $env->entities->getRepository('comment')->find((int) $malformed->id());
        self::assertNotNull($entity);
        self::assertFalse($env->accessHandler->check($entity, 'view', $env->bob)->isAllowed());
        try {
            $env->reads->run($env->bob, fn () => $entity->get('body'));
            self::fail('Protected body reads must reject an active but unpublished comment.');
        } catch (FieldReadDenied) {
            self::addToAssertionCount(1);
        }
        try {
            $env->service->getComment($env->bob, $malformed->uuid());
            self::fail('Normal service reads must reject malformed unpublished comments.');
        } catch (EngagementException|FieldReadDenied $failure) {
            if ($failure instanceof EngagementException) {
                self::assertSame('TARGET_UNAVAILABLE', $failure->errorCode);
            } else {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[DataProvider('storageLayouts')]
    public function empty_reaction_type_fails_closed_while_legacy_nonlike_reactions_remain_readable(bool $columnLayout): void
    {
        $env = new ServiceEnvironment($columnLayout);
        $legacy = new Reaction([
            'user_id' => 1, 'target_type' => 'service_root', 'target_id' => (int) $env->root->id(),
            'reaction_type' => 'love', 'root_uuid' => $env->root->uuid(), 'lifecycle' => 'active', 'created_at' => $env->now,
        ]);
        $empty = new Reaction([
            'user_id' => 1, 'target_type' => 'service_root', 'target_id' => (int) $env->root->id(),
            'reaction_type' => '', 'root_uuid' => $env->root->uuid(), 'lifecycle' => 'active', 'created_at' => $env->now,
        ]);
        $env->database->transactional(fn () => $env->scope->runMigration(function () use ($env, $legacy, $empty): void {
            $repository = $env->entities->getRepository('reaction');
            $repository->save($legacy, false);
            $repository->save($empty, false);
        }));

        $legacyLoaded = $env->entities->getRepository('reaction')->find((int) $legacy->id());
        $emptyLoaded = $env->entities->getRepository('reaction')->find((int) $empty->id());
        self::assertTrue($env->accessHandler->check($legacyLoaded, 'view', $env->bob)->isAllowed());
        self::assertSame('love', $env->database->transactional(fn () => $env->scope->runMigration(
            fn () => $legacyLoaded->get('reaction_type'),
        )));
        self::assertFalse($env->accessHandler->check($emptyLoaded, 'view', $env->bob)->isAllowed());
        try {
            $env->reads->run($env->bob, fn () => $emptyLoaded->get('reaction_type'));
            self::fail('An empty reaction type must not receive legacy non-like visibility.');
        } catch (FieldReadDenied) {
            self::addToAssertionCount(1);
        }
    }

    #[Test]
    public function provider_resolves_domain_service_and_registers_upgrade_command(): void
    {
        $env = new ServiceEnvironment();
        $lockDir = sys_get_temp_dir() . '/waaseyaa_engagement_provider_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($lockDir, 0700, true));
        try {
            $provider = new EngagementServiceProvider();
            $provider->setKernelContext(sys_get_temp_dir(), ['engagement' => ['installation_id' => 'provider-test', 'lock_directory' => $lockDir]], []);
            $provider->setKernelServices(new class ($env) implements KernelServicesInterface {
                public function __construct(private ServiceEnvironment $env) {}
                public function get(string $abstract): ?object
                {
                    return match ($abstract) {
                        DatabaseInterface::class => $this->env->database,
                        EntityTypeManagerInterface::class => $this->env->entities,
                        EntityAccessHandler::class => $this->env->accessHandler,
                        EngagementPolicyInterface::class => $this->env->policy,
                        AccountContextInterface::class => $this->env->accountContext,
                        AccountFieldReadScopeInterface::class => $this->env->reads,
                        ApplicationMasterKeyring::class => ServiceEnvironment::keyring(),
                        default => null,
                    };
                }
            });
            $provider->register();

            self::assertInstanceOf(EngagementService::class, $provider->resolve(EngagementService::class));
            $commands = iterator_to_array($provider->consoleCommands());
            self::assertSame([EngagementUpgradeCommand::class], $commands);
            $command = $provider->resolve(EngagementUpgradeCommand::class);
            self::assertInstanceOf(EngagementUpgradeCommand::class, $command);
            $tester = new CommandTester($command);
            self::assertSame(0, $tester->execute([]));
            $output = $tester->getDisplay();
            self::assertStringContainsString('"event":"engagement-upgrade-batch"', $output);
            self::assertStringContainsString('"scanned":0', $output);
            self::assertStringContainsString('Engagement upgrade complete: scanned=0 migrated=0 quarantined=0 duplicate_likes_removed=0', $output);
            self::assertStringNotContainsString('legacy hidden', $output);
        } finally {
            foreach (glob($lockDir . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
            rmdir($lockDir);
        }
    }
}
