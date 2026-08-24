<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Messaging\EventSubscriber\ThreadParticipantBootstrapSubscriber;
use Waaseyaa\Messaging\MessageThread;
use Waaseyaa\Messaging\MessagingAccessPolicy;
use Waaseyaa\Messaging\Tests\Unit\MessagingFieldReadTestTrait;
use Waaseyaa\Messaging\ThreadMessage;
use Waaseyaa\Messaging\ThreadParticipant;

/**
 * End-to-end regression test for the participant-bootstrap deadlock
 * (audit L3-messaging.md, #1915, R16): `MessagingAccessPolicy::fieldAccess()`
 * required the acting account to already be a `thread_participant` before it
 * could create the FIRST such row for a thread, and nothing seeded that first
 * membership — so an ordinary non-admin could create a `message_thread` but
 * could never read or post to it afterwards. These tests exercise the real
 * `EntityRepository` save pipeline (not mocks) so the fix is proven against
 * the actual event-dispatch order, not an assumption about it.
 */
#[CoversClass(ThreadParticipantBootstrapSubscriber::class)]
final class ThreadParticipantBootstrapSubscriberTest extends TestCase
{
    use MessagingFieldReadTestTrait {
        setUp as private setUpFieldReads;
        tearDown as private tearDownFieldReads;
    }

    private const CREATOR_UID = 100;
    private const OUTSIDER_UID = 200;

    protected function setUp(): void
    {
        $this->setUpFieldReads();
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        $this->tearDownFieldReads();
    }

    #[Test]
    public function creating_a_thread_seeds_the_creator_as_owner_participant(): void
    {
        [$manager] = $this->makeManagerAndSubscriber(self::CREATOR_UID);

        $threadRepository = $manager->getRepository('message_thread');
        $participantRepository = $manager->getRepository('thread_participant');

        $thread = $threadRepository->create(['created_by' => self::CREATOR_UID]);
        $threadRepository->save($thread, validate: false);

        $rows = $participantRepository->findBy([
            'thread_id' => (int) $thread->id(),
            'user_id' => self::CREATOR_UID,
        ]);

        self::assertCount(1, $rows, 'The creating account must be auto-seeded as a thread_participant.');
        self::assertSame('owner', $this->readMessaging(fn(): mixed => $rows[0]->get('role')));
    }

    #[Test]
    public function the_creator_can_immediately_read_and_post_while_an_outsider_still_cannot(): void
    {
        [$manager] = $this->makeManagerAndSubscriber(self::CREATOR_UID);

        $threadRepository = $manager->getRepository('message_thread');
        $messageRepository = $manager->getRepository('thread_message');

        $thread = $threadRepository->create(['created_by' => self::CREATOR_UID]);
        $threadRepository->save($thread, validate: false);

        $handler = new EntityAccessHandler([new MessagingAccessPolicy($manager)]);
        $creator = $this->account(self::CREATOR_UID);
        $outsider = $this->account(self::OUTSIDER_UID);

        // Read access: the creator can see the thread they just created; an
        // outsider (not a participant) cannot — no manual seeding step needed.
        self::assertTrue(
            $handler->check($thread, 'view', $creator)->isAllowed(),
            'The thread creator must be able to read the thread they just created.',
        );
        self::assertFalse(
            $handler->check($thread, 'view', $outsider)->isAllowed(),
            'A non-participant must not be able to read the thread.',
        );

        // Post access: constructing a thread_message and checking field-level
        // 'edit' access mirrors how JsonApiController::store() gates creation.
        $message = $messageRepository->create([
            'thread_id' => (int) $thread->id(),
            'sender_id' => self::CREATOR_UID,
            'body' => 'hello',
        ]);

        self::assertFalse(
            $handler->checkFieldAccess($message, 'body', 'edit', $creator)->isForbidden(),
            'The thread creator must be able to post to the thread they just created.',
        );
        self::assertTrue(
            $handler->checkFieldAccess($message, 'body', 'edit', $outsider)->isForbidden(),
            'A non-participant must not be able to post to the thread.',
        );
    }

    #[Test]
    public function no_acting_account_leaves_the_thread_without_a_seeded_participant(): void
    {
        // Null AccountContextInterface (CLI/system context) — degrade gracefully,
        // never throw, and leave the thread without an auto-seeded participant.
        [$manager] = $this->makeManagerAndSubscriber(actingAccountId: null);

        $threadRepository = $manager->getRepository('message_thread');
        $participantRepository = $manager->getRepository('thread_participant');

        $thread = $threadRepository->create(['created_by' => self::CREATOR_UID]);
        $threadRepository->save($thread, validate: false);

        self::assertSame([], $participantRepository->findBy(['thread_id' => (int) $thread->id()]));
    }

    #[Test]
    public function updating_an_existing_thread_does_not_reseed_a_participant(): void
    {
        [$manager] = $this->makeManagerAndSubscriber(self::CREATOR_UID);

        $threadRepository = $manager->getRepository('message_thread');
        $participantRepository = $manager->getRepository('thread_participant');

        $thread = $threadRepository->create(['created_by' => self::CREATOR_UID]);
        $threadRepository->save($thread, validate: false);

        $thread->set('title', 'Renamed');
        $threadRepository->save($thread, validate: false);

        self::assertCount(
            1,
            $participantRepository->findBy(['thread_id' => (int) $thread->id(), 'user_id' => self::CREATOR_UID]),
            'An update to an existing thread must not create a second participant row.',
        );
    }

    #[Test]
    public function save_many_existing_then_new_seeds_only_the_new_thread(): void
    {
        [$manager] = $this->makeManagerAndSubscriber(self::CREATOR_UID);
        $threadRepository = $manager->getRepository('message_thread');
        $participantRepository = $manager->getRepository('thread_participant');

        $existing = $threadRepository->create(['created_by' => self::CREATOR_UID, 'title' => 'Existing']);
        $threadRepository->save($existing, validate: false);
        $existing->set('title', 'Existing renamed');

        $new = $threadRepository->create(['created_by' => self::CREATOR_UID, 'title' => 'Brand new']);
        $threadRepository->saveMany([$existing, $new], validate: false);

        self::assertCount(
            1,
            $participantRepository->findBy(['thread_id' => (int) $existing->id(), 'user_id' => self::CREATOR_UID]),
            'Updating an existing thread in a mixed saveMany batch must not reseed its owner.',
        );
        self::assertCount(
            1,
            $participantRepository->findBy(['thread_id' => (int) $new->id(), 'user_id' => self::CREATOR_UID]),
            'The new thread in saveMany([existing, new]) must still receive its owner participant.',
        );
    }

    #[Test]
    public function save_many_new_then_existing_seeds_only_the_new_thread(): void
    {
        [$manager] = $this->makeManagerAndSubscriber(self::CREATOR_UID);
        $threadRepository = $manager->getRepository('message_thread');
        $participantRepository = $manager->getRepository('thread_participant');

        $existing = $threadRepository->create(['created_by' => self::CREATOR_UID, 'title' => 'Existing']);
        $threadRepository->save($existing, validate: false);
        $existing->set('title', 'Existing renamed');

        $new = $threadRepository->create(['created_by' => self::CREATOR_UID, 'title' => 'Brand new']);
        $threadRepository->saveMany([$new, $existing], validate: false);

        self::assertCount(
            1,
            $participantRepository->findBy(['thread_id' => (int) $new->id(), 'user_id' => self::CREATOR_UID]),
            'The new thread in saveMany([new, existing]) must still receive its owner participant.',
        );
        self::assertCount(
            1,
            $participantRepository->findBy(['thread_id' => (int) $existing->id(), 'user_id' => self::CREATOR_UID]),
            'Updating an existing thread in a mixed saveMany batch must not reseed its owner.',
        );
    }

    /**
     * @return array{0: EntityTypeManager}
     */
    private function makeManagerAndSubscriber(?int $actingAccountId): array
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();

        $resolver = new SingleConnectionResolver($database);
        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $registry): EntityRepository {
                new SqlSchemaHandler($definition, $database, $registry)->ensureTable();

                $idKey = $definition->getKeys()['id'] ?? 'id';

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $database,
                    fieldRegistry: $registry,
                );
            },
            fieldRegistry: $registry,
        );

        ContentEntityBase::setFieldRegistry($registry);

        $manager->registerEntityType(EntityType::fromClass(MessageThread::class, group: 'messaging'));
        $manager->registerEntityType(EntityType::fromClass(ThreadParticipant::class, group: 'messaging'));
        $manager->registerEntityType(EntityType::fromClass(ThreadMessage::class, group: 'messaging'));

        $accountContext = $this->accountContext($actingAccountId);
        $dispatcher->addSubscriber(new ThreadParticipantBootstrapSubscriber($manager, $accountContext));

        return [$manager];
    }

    private function accountContext(?int $actingAccountId): ?AccountContextInterface
    {
        if ($actingAccountId === null) {
            return null;
        }

        $account = $this->account($actingAccountId);

        $context = $this->createStub(AccountContextInterface::class);
        $context->method('current')->willReturn($account);

        return $context;
    }

    private function account(int $uid): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn($uid);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn(true);

        return $account;
    }
}
