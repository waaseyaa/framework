<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\UserProvisioning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleProvisioningResult;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Exception\EntityMutationCommittedSideEffectsFailedException;
use Waaseyaa\User\RegisteredRoleAssignmentService;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\User\User;

#[CoversClass(RegisteredRoleAccountProvisioner::class)]
#[CoversClass(RegisteredRoleProvisioningResult::class)]
final class RegisteredRoleAccountProvisionerTest extends TestCase
{
    private const PASSWORD = 'private-test-password';

    private function provisioner(
        UserIdentityLookupInterface $identities,
        UserInternalFieldReaderInterface $fields,
    ): RegisteredRoleAccountProvisioner {
        return new RegisteredRoleAccountProvisioner(
            new RegisteredRoleAssignmentService(new RoleRepository([
                new Role('contributor', 'Contributor', ['create events', 'edit own events']),
                new Role('administrator', 'Administrator', ['administer site']),
            ])),
            $identities,
            $fields,
            1_800_000_000,
        );
    }

    private function noIdentity(): UserIdentityLookupInterface
    {
        $lookup = $this->createStub(UserIdentityLookupInterface::class);
        $lookup->method('findActiveByLogin')->willReturn(null);
        $lookup->method('findActiveByMail')->willReturn(null);

        return $lookup;
    }

    #[Test]
    public function createsOneFullyAuthorizedAccountInOneSave(): void
    {
        $fields = $this->createStub(UserInternalFieldReaderInterface::class);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $user = new User(['uid' => 17]);
        $repository->expects(self::once())->method('create')->with(self::callback(static function (array $values): bool {
            self::assertSame('community-owner', $values['name']);
            self::assertSame('owner@example.test', $values['mail']);
            self::assertArrayNotHasKey('identity_name_key', $values);
            self::assertArrayNotHasKey('identity_mail_key', $values);
            self::assertSame(['contributor'], $values['roles']);
            self::assertSame(['create events', 'edit own events'], $values['permissions']);
            self::assertTrue(password_verify(self::PASSWORD, $values['pass']));
            self::assertSame(1_800_000_000, $values['created']);

            return true;
        }))->willReturn($user);
        $repository->expects(self::once())->method('save')->with($user)->willReturn(1);

        $result = $this->provisioner($this->noIdentity(), $fields)->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('created', $result->status);
        self::assertSame('17', $result->accountId);
        self::assertStringNotContainsString(self::PASSWORD, $result->canonicalJson());
    }

    #[Test]
    public function exactRetryReturnsExistingWithoutSaveOrCredentialReset(): void
    {
        $user = new User(['uid' => 23]);
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $identities->method('findActiveByLogin')->willReturn($user);
        $identities->method('findActiveByMail')->willReturn($user);
        $fields = $this->createStub(UserInternalFieldReaderInterface::class);
        $fields->method('mailDelivery')->willReturn(new UserMailSnapshot('community-owner', 'owner@example.test'));
        $fields->method('credentials')->willReturn(new UserCredentialSnapshot(true, password_hash(self::PASSWORD, PASSWORD_DEFAULT)));
        $fields->method('maintenanceAuthorization')->willReturn(new UserAuthorizationSnapshot(
            ['contributor'],
            ['create events', 'edit own events'],
        ));
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');

        $result = $this->provisioner($identities, $fields)->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('existing', $result->status);
        self::assertSame('23', $result->accountId);
    }

    #[Test]
    public function conflictingRetryNeverWrites(): void
    {
        $user = new User(['uid' => 23]);
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $identities->method('findActiveByLogin')->willReturn($user);
        $identities->method('findActiveByMail')->willReturn($user);
        $fields = $this->createStub(UserInternalFieldReaderInterface::class);
        $fields->method('mailDelivery')->willReturn(new UserMailSnapshot('community-owner', 'owner@example.test'));
        $fields->method('credentials')->willReturn(new UserCredentialSnapshot(true, password_hash('different-password', PASSWORD_DEFAULT)));
        $fields->method('maintenanceAuthorization')->willReturn(new UserAuthorizationSnapshot(['contributor'], ['create events', 'edit own events']));
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $result = $this->provisioner($identities, $fields)->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('refused', $result->status);
        self::assertSame('identity_conflict', $result->code);
    }

    #[Test]
    public function ambiguousHistoricalMailIsNotTreatedAsAnUnusedIdentity(): void
    {
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $identities->method('findActiveByLogin')->willReturn(null);
        $identities->method('findActiveByMail')->willReturn(null);
        $identities->method('mailExists')->willReturn(true);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');

        $result = $this->provisioner($identities, $this->createStub(UserInternalFieldReaderInterface::class))->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('refused', $result->status);
        self::assertSame('identity_conflict', $result->code);
    }

    #[Test]
    public function inactiveHistoricalLoginIsNotTreatedAsAnUnusedIdentity(): void
    {
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $identities->method('findActiveByLogin')->willReturn(null);
        $identities->method('findActiveByMail')->willReturn(null);
        $identities->method('loginExists')->willReturn(true);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');

        $result = $this->provisioner($identities, $this->createStub(UserInternalFieldReaderInterface::class))->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('refused', $result->status);
        self::assertSame('identity_conflict', $result->code);
    }

    #[Test]
    public function invalidOrReservedRoleNeverTouchesStorage(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');
        $fields = $this->createStub(UserInternalFieldReaderInterface::class);
        $provisioner = $this->provisioner($this->noIdentity(), $fields);

        self::assertSame('unknown_role', $provisioner->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'missing',
            self::PASSWORD,
        )->code);
        self::assertSame('reserved_role', $provisioner->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'administrator',
            self::PASSWORD,
        )->code);
    }

    #[Test]
    public function failureBeforeAccountConstructionIsRefusedWithoutDetail(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('create')->willThrowException(new \RuntimeException('storage detail ' . self::PASSWORD));
        $repository->expects(self::never())->method('save');

        $result = $this->provisioner($this->noIdentity(), $this->createStub(UserInternalFieldReaderInterface::class))->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('refused', $result->status);
        self::assertSame('storage_failure', $result->code);
        self::assertStringNotContainsString(self::PASSWORD, $result->canonicalJson());
        self::assertStringNotContainsString('storage detail', $result->canonicalJson());
    }

    #[Test]
    public function failedSaveWithUnobservableOutcomeIsUncertainWithoutDetail(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('create')->willReturn(new User(['uid' => 31]));
        $repository->method('save')->willThrowException(new \RuntimeException('storage detail ' . self::PASSWORD));

        $result = $this->provisioner($this->noIdentity(), $this->createStub(UserInternalFieldReaderInterface::class))->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('uncertain', $result->status);
        self::assertSame('storage_outcome_unresolved', $result->code);
        self::assertStringNotContainsString(self::PASSWORD, $result->canonicalJson());
        self::assertStringNotContainsString('storage detail', $result->canonicalJson());
    }

    #[Test]
    public function postCommitCompletionFailureIsUncertain(): void
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('create')->willReturn(new User(['uid' => 37]));
        $repository->method('save')->willThrowException(new EntityMutationCommittedSideEffectsFailedException(
            new TransactionCompletionException([new \RuntimeException('completion detail ' . self::PASSWORD)], 'unit-token'),
        ));

        $result = $this->provisioner($this->noIdentity(), $this->createStub(UserInternalFieldReaderInterface::class))->provision(
            $repository,
            'community-owner',
            'owner@example.test',
            'contributor',
            self::PASSWORD,
        );

        self::assertSame('uncertain', $result->status);
        self::assertSame('account_committed_completion_failed', $result->code);
        self::assertSame('37', $result->accountId);
        self::assertStringNotContainsString(self::PASSWORD, $result->canonicalJson());
    }
}
