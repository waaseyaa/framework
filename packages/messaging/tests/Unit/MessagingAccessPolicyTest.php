<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Messaging\MessagingAccessPolicy;
use Waaseyaa\Messaging\ThreadMessage;

/**
 * The README/spec promise that "only participants can read or post". These tests
 * exercise that promise through the same EntityAccessHandler surface the
 * JSON:API controller uses: `check(..., 'view')` for reads, `checkFieldAccess(...,
 * 'edit')` for posts/edits (store() runs the field check on the constructed
 * message, which already carries thread_id).
 */
#[CoversClass(MessagingAccessPolicy::class)]
final class MessagingAccessPolicyTest extends TestCase
{
    private const THREAD_ID = 1;
    private const PARTICIPANT_UID = 100;
    private const OUTSIDER_UID = 200;

    #[Test]
    public function a_participant_can_read_a_thread_message_but_an_outsider_cannot(): void
    {
        $handler = $this->handler();
        $message = $this->threadMessage(self::THREAD_ID);

        self::assertTrue(
            $handler->check($message, 'view', $this->account(self::PARTICIPANT_UID))->isAllowed(),
            'A thread participant must be able to read the message.',
        );
        self::assertFalse(
            $handler->check($message, 'view', $this->account(self::OUTSIDER_UID))->isAllowed(),
            'A non-participant must NOT be able to read the message.',
        );
    }

    #[Test]
    public function an_outsider_cannot_post_to_a_thread(): void
    {
        // store() runs checkFieldAccess('edit') on the constructed message, which
        // carries the target thread_id — the only create-time hook that sees it.
        $handler = $this->handler();
        $message = $this->threadMessage(self::THREAD_ID);

        self::assertTrue(
            $handler->checkFieldAccess($message, 'body', 'edit', $this->account(self::OUTSIDER_UID))->isForbidden(),
            'A non-participant must NOT be able to post to the thread.',
        );
        self::assertFalse(
            $handler->checkFieldAccess($message, 'body', 'edit', $this->account(self::PARTICIPANT_UID))->isForbidden(),
            'A participant must be able to post to the thread.',
        );
    }

    #[Test]
    public function any_authenticated_account_may_start_a_new_thread_but_anonymous_may_not(): void
    {
        $policy = new MessagingAccessPolicy($this->entityTypeManager());

        self::assertTrue($policy->createAccess('message_thread', 'message_thread', $this->account(self::OUTSIDER_UID, authenticated: true))->isAllowed());
        self::assertFalse($policy->createAccess('message_thread', 'message_thread', $this->account(0, authenticated: false))->isAllowed());
    }

    #[Test]
    public function protected_message_fields_use_the_exact_participant_principal(): void
    {
        $handler = $this->handler();
        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...)));
        $message = new ThreadMessage(['thread_id' => self::THREAD_ID, 'sender_id' => 2, 'body' => 'Private body']);

        try {
            $participant = new AuthorizationPrincipal(self::PARTICIPANT_UID, true, [], [], 'messaging-policy-test');
            self::assertSame('Private body', $scope->run($participant, fn(): mixed => $message->get('body')));

            $this->expectException(FieldReadDenied::class);
            $outsider = new AuthorizationPrincipal(self::OUTSIDER_UID, true, [], [], 'messaging-policy-test');
            $scope->run($outsider, fn(): mixed => $message->get('body'));
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }

    private function handler(): EntityAccessHandler
    {
        return new EntityAccessHandler([new MessagingAccessPolicy($this->entityTypeManager())]);
    }

    /** EntityTypeManager whose thread_participant query returns a row only for (THREAD_ID, PARTICIPANT_UID). */
    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $captured = ['thread_id' => null, 'user_id' => null];

        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('condition')->willReturnCallback(
            function (string $field, mixed $value) use (&$captured, $query): EntityQueryInterface {
                $captured[$field] = $value;

                return $query;
            },
        );
        $query->method('execute')->willReturnCallback(
            function () use (&$captured): array {
                return ((int) $captured['thread_id'] === self::THREAD_ID
                    && (int) $captured['user_id'] === self::PARTICIPANT_UID) ? ['1'] : [];
            },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        return $etm;
    }

    private function threadMessage(int $threadId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('thread_message');
        $entity->method('bundle')->willReturn('');
        $entity->method('get')->willReturnCallback(
            static fn(string $f): mixed => $f === 'thread_id' ? $threadId : null,
        );

        return $entity;
    }

    private function account(int $uid, bool $authenticated = true): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($uid);
        $account->method('hasPermission')->willReturn(false);
        $account->method('isAuthenticated')->willReturn($authenticated);

        return $account;
    }
}
