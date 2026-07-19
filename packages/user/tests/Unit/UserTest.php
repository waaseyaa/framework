<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\InternalFieldArrayExportDenied;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Validation\ValidationFieldReader;
use Waaseyaa\Entity\Validation\ValidationReadLedgerInterface;
use Waaseyaa\Entity\Validation\ValidationReadReservationInterface;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Symfony\Component\Validator\Validation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    private AccountFieldReadScope $scope;

    private UserInternalFieldReaderFixture $internal;

    protected function setUp(): void
    {
        $this->scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new UserAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($this->scope, $handler->checkProtectedFieldRead(...)));
        $this->internal = new UserInternalFieldReaderFixture();
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    // -----------------------------------------------------------------
    // Construction and entity basics
    // -----------------------------------------------------------------

    public function testExtendsContentEntityBase(): void
    {
        $user = new User();
        $this->assertInstanceOf(ContentEntityBase::class, $user);
    }

    public function testImplementsAccountInterface(): void
    {
        $user = new User();
        $this->assertInstanceOf(AccountInterface::class, $user);
    }

    public function testEntityTypeId(): void
    {
        $user = new User();
        $this->assertSame('user', $user->getEntityTypeId());
    }

    public function testNewUserHasNoUid(): void
    {
        $user = new User();
        // No uid passed, so id() returns 0 (int cast of null).
        $this->assertSame(0, $user->id());
    }

    public function testNewUserIsNew(): void
    {
        $user = new User();
        // uid is not set -> parent id() returns null -> isNew() is true.
        $this->assertTrue($user->isNew());
    }

    public function testUserWithUidIsNotNew(): void
    {
        $user = new User(['uid' => 42]);
        $this->assertSame(42, $user->id());
        $this->assertFalse($user->isNew());
    }

    public function testAutoGeneratesUuid(): void
    {
        $user = new User();
        $uuid = $user->uuid();
        $this->assertNotEmpty($uuid);
        // UUID v4 format check.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function testExplicitUuidIsPreserved(): void
    {
        $user = new User(['uuid' => 'my-custom-uuid']);
        $this->assertSame('my-custom-uuid', $user->uuid());
    }

    public function testLabelReturnsName(): void
    {
        $user = new User(['name' => 'alice']);
        $this->assertSame('alice', $this->asAdmin(static fn(): string => $user->label()));
    }

    // -----------------------------------------------------------------
    // Name
    // -----------------------------------------------------------------

    public function testGetSetName(): void
    {
        $user = new User();
        $this->assertSame('', $user->getName());

        $user->setName('bob');
        $this->assertSame('bob', $this->asAdmin(static fn(): string => $user->getName()));
    }

    public function testNameViaConstructor(): void
    {
        $user = new User(['name' => 'charlie']);
        $this->assertSame('charlie', $this->asAdmin(static fn(): string => $user->getName()));
    }

    // -----------------------------------------------------------------
    // Email
    // -----------------------------------------------------------------

    public function testGetSetEmail(): void
    {
        $user = new User();
        $this->assertSame('', $user->getEmail());

        $user->setEmail('alice@example.com');
        $this->assertSame('alice@example.com', $this->internal->mailDelivery($user)->mail);
    }

    public function testEmailViaConstructor(): void
    {
        $user = new User(['mail' => 'bob@example.com']);
        $this->assertSame('bob@example.com', $this->internal->mailDelivery($user)->mail);
    }

    // -----------------------------------------------------------------
    // Password hashing and verification
    // -----------------------------------------------------------------

    public function testSetRawPasswordHashesAndStores(): void
    {
        $user = new User();
        $user->setRawPassword('secret123');

        $hash = $this->internal->credentials($user)->passwordHash;
        $this->assertNotEmpty($hash);
        $this->assertNotSame('secret123', $hash);
        // The hash should be verifiable.
        $this->assertTrue(password_verify('secret123', $hash));
    }

    public function testAuditedCredentialSnapshotVerifiesCorrectPassword(): void
    {
        $user = new User();
        $user->setRawPassword('p@ssw0rd');

        $this->assertTrue(password_verify('p@ssw0rd', $this->internal->credentials($user)->passwordHash));
    }

    public function testAuditedCredentialSnapshotRejectsWrongPassword(): void
    {
        $user = new User();
        $user->setRawPassword('correct-horse');

        $this->assertFalse(password_verify('wrong-battery', $this->internal->credentials($user)->passwordHash));
    }

    public function testCheckPasswordReturnsFalseWhenNoPasswordSet(): void
    {
        $user = new User();
        $this->assertFalse($user->checkPassword('anything'));
    }

    public function testSetPasswordDirectly(): void
    {
        $hash = password_hash('manual', \PASSWORD_DEFAULT);
        $user = new User();
        $user->setPassword($hash);

        $this->assertSame($hash, $this->internal->credentials($user)->passwordHash);
        $this->assertTrue(password_verify('manual', $this->internal->credentials($user)->passwordHash));
    }

    // -----------------------------------------------------------------
    // Roles
    // -----------------------------------------------------------------

    public function testDefaultRolesAreEmpty(): void
    {
        $user = new User();
        $this->assertSame([], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testSetRoles(): void
    {
        $user = new User();
        $user->setRoles(['editor', 'admin']);
        $this->assertSame(['editor', 'admin'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testRolesViaConstructor(): void
    {
        $user = new User(['roles' => ['authenticated', 'editor']]);
        $this->assertSame(['authenticated', 'editor'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testAddRole(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $roles = $this->internal->maintenanceAuthorization($user)->roles;
        $roles[] = 'editor';
        $user->setRoles(array_values(array_unique($roles)));
        $this->assertSame(['authenticated', 'editor'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testAddRoleDoesNotDuplicate(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $roles = $this->internal->maintenanceAuthorization($user)->roles;
        $roles[] = 'authenticated';
        $user->setRoles(array_values(array_unique($roles)));
        $this->assertSame(['authenticated'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testRemoveRole(): void
    {
        $user = new User(['roles' => ['authenticated', 'editor', 'admin']]);
        $roles = array_values(array_filter(
            $this->internal->maintenanceAuthorization($user)->roles,
            static fn(string $role): bool => $role !== 'editor',
        ));
        $user->setRoles($roles);
        $this->assertSame(['authenticated', 'admin'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    public function testRemoveNonExistentRoleIsNoOp(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $roles = array_values(array_filter(
            $this->internal->maintenanceAuthorization($user)->roles,
            static fn(string $role): bool => $role !== 'nonexistent',
        ));
        $user->setRoles($roles);
        $this->assertSame(['authenticated'], $this->internal->maintenanceAuthorization($user)->roles);
    }

    // -----------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------

    public function testDefaultPermissionsAreEmpty(): void
    {
        $user = new User();
        $snapshot = $this->internal->maintenanceAuthorization($user);
        $this->assertFalse($this->principalFrom($user, $snapshot->roles, $snapshot->permissions)->hasPermission('anything'));
    }

    public function testHasPermission(): void
    {
        $user = new User(['permissions' => ['edit content', 'view content']]);
        $snapshot = $this->internal->maintenanceAuthorization($user);
        $principal = $this->principalFrom($user, $snapshot->roles, $snapshot->permissions);
        $this->assertTrue($principal->hasPermission('edit content'));
        $this->assertTrue($principal->hasPermission('view content'));
        $this->assertFalse($principal->hasPermission('delete content'));
    }

    public function testSetPermissions(): void
    {
        $user = new User();
        $user->setPermissions(['administer site']);
        $snapshot = $this->internal->maintenanceAuthorization($user);
        $this->assertTrue($this->principalFrom($user, $snapshot->roles, $snapshot->permissions)->hasPermission('administer site'));
    }

    public function testAdministratorRoleGrantsAllPermissions(): void
    {
        $user = new User(['roles' => ['administrator']]);
        $snapshot = $this->internal->maintenanceAuthorization($user);
        $principal = $this->principalFrom($user, $snapshot->roles, $snapshot->permissions);
        $this->assertTrue($principal->hasPermission('edit content'));
        $this->assertTrue($principal->hasPermission('delete users'));
        $this->assertTrue($principal->hasPermission('any arbitrary permission'));
    }

    public function testNonAdministratorDoesNotGetImplicitPermissions(): void
    {
        $user = new User(['roles' => ['editor']]);
        $snapshot = $this->internal->maintenanceAuthorization($user);
        $this->assertFalse($this->principalFrom($user, $snapshot->roles, $snapshot->permissions)->hasPermission('delete users'));
    }

    // -----------------------------------------------------------------
    // Authentication status
    // -----------------------------------------------------------------

    public function testIsAuthenticatedWithUid(): void
    {
        $user = new User(['uid' => 1]);
        $this->assertTrue($user->isAuthenticated());
    }

    public function testIsNotAuthenticatedWithUidZero(): void
    {
        $user = new User(['uid' => 0]);
        $this->assertFalse($user->isAuthenticated());
    }

    public function testIsNotAuthenticatedWithNoUid(): void
    {
        $user = new User();
        $this->assertFalse($user->isAuthenticated());
    }

    // W3-5 contract: isAuthenticated requires a persisted identity (not just id != 0).

    public function testIsAuthenticatedFalseForUnsavedUserWithNoUid(): void
    {
        // A freshly constructed user with no uid is isNew() and must not be authenticated.
        $user = new User([]);
        $this->assertTrue($user->isNew());
        $this->assertFalse($user->isAuthenticated());
    }

    public function testIsAuthenticatedTrueForUserWithUid(): void
    {
        // A user constructed with a uid is treated as persisted (not new) by the constructor.
        $user = new User(['uid' => 42]);
        $this->assertTrue($user->isAuthenticated());
    }

    public function testIsAuthenticatedFalseForUidZero(): void
    {
        // uid 0 is the anonymous user — never authenticated.
        $user = new User(['uid' => 0]);
        $this->assertFalse($user->isAuthenticated());
    }

    // -----------------------------------------------------------------
    // Active / blocked status
    // -----------------------------------------------------------------

    public function testIsActiveByDefault(): void
    {
        $user = new User();
        $this->assertTrue($this->asAdmin(static fn(): bool => $user->isActive()));
    }

    public function testSetActiveToFalse(): void
    {
        $user = new User();
        $user->setActive(false);
        $this->assertFalse($this->asAdmin(static fn(): bool => $user->isActive()));
    }

    public function testSetActiveToTrue(): void
    {
        $user = new User(['status' => 0]);
        $this->assertFalse($this->asAdmin(static fn(): bool => $user->isActive()));

        $user->setActive(true);
        $this->assertTrue($this->asAdmin(static fn(): bool => $user->isActive()));
    }

    public function testSetActiveResultValidatesUnderDerivedBooleanConstraints(): void
    {
        // #2064 alpha.270: definition, mutation, closed validation, and guarded
        // read all use the same native-bool representation.
        $constraints = EntityTypeValidationConstraints::forEntityType(EntityType::fromClass(User::class));
        $ledger = new class implements ValidationReadLedgerInterface {
            public function reserve(\Waaseyaa\Entity\EntityStructure $subject, string $field): ValidationReadReservationInterface
            {
                return new class implements ValidationReadReservationInterface {
                    public function finalize(bool $success): void {}
                };
            }
        };
        $validator = new EntityValidator(Validation::createValidator(), new ValidationFieldReader($ledger));

        $user = User::make(['name' => 'pat', 'mail' => 'pat@example.com']);

        $user->setActive(true);
        $this->assertCount(0, $validator->validate($user, $constraints));

        $user->setActive(false);
        $this->assertCount(0, $validator->validate($user, $constraints));
    }

    public function testSetActiveTrueStoresCanonicalBool(): void
    {
        $user = new User();
        $user->setActive(true);
        $this->assertTrue($this->asAdmin(static fn(): bool => $user->isActive()));
        $this->assertSame(true, $this->asAdmin(static fn(): mixed => $user->get('status')));
    }

    public function testSetActiveFalseStoresCanonicalBool(): void
    {
        $user = new User();
        $user->setActive(false);
        $this->assertFalse($this->asAdmin(static fn(): bool => $user->isActive()));
        $this->assertSame(false, $this->asAdmin(static fn(): mixed => $user->get('status')));
    }

    // -----------------------------------------------------------------
    // Email verification
    // -----------------------------------------------------------------

    public function testSetEmailVerifiedTrueRoundTrips(): void
    {
        $user = new User();
        $user->setEmailVerified(true);
        $this->assertTrue($this->internal->verification($user)->emailVerified);
    }

    public function testSetEmailVerifiedFalseRoundTrips(): void
    {
        $user = new User();
        $user->setEmailVerified(false);
        $this->assertFalse($this->internal->verification($user)->emailVerified);
    }

    // W3-5 contract: email_verified setter stores a real bool (cast + property + setter agree).

    public function testSetEmailVerifiedStoresBoolNotInt(): void
    {
        // The bool cast and bool property declaration mean the stored value
        // must be a native bool, not int 1.
        $user = new User();
        $user->setEmailVerified(true);
        $this->assertTrue($this->internal->verification($user)->emailVerified);
    }

    // -----------------------------------------------------------------
    // toArray
    // -----------------------------------------------------------------

    public function testToArrayFailsAtomicallyWhenInternalValuesArePresent(): void
    {
        $user = new User([
            'uid' => 5,
            'name' => 'dave',
            'mail' => 'dave@example.com',
            'roles' => ['admin'],
            'permissions' => ['do stuff'],
            'status' => 1,
        ]);

        $this->expectException(InternalFieldArrayExportDenied::class);
        $user->toArray();
    }

    // -----------------------------------------------------------------
    // Fluent interface
    // -----------------------------------------------------------------

    public function testSettersReturnSelf(): void
    {
        $user = new User();

        $this->assertSame($user, $user->setName('x'));
        $this->assertSame($user, $user->setEmail('x@x.com'));
        $this->assertSame($user, $user->setRawPassword('pw'));
        $this->assertSame($user, $user->setPassword('hash'));
        $this->assertSame($user, $user->setRoles([]));
        $this->assertSame($user, $user->setPermissions([]));
        $this->assertSame($user, $user->setActive(true));
    }

    // -----------------------------------------------------------------
    // Final class
    // -----------------------------------------------------------------

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(User::class);
        $this->assertTrue($reflection->isFinal());
    }

    private function asAdmin(callable $callback): mixed
    {
        return $this->scope->run(
            new AuthorizationPrincipal(1, true, ['member'], ['administer users'], 'user-test-admin'),
            $callback,
        );
    }

    /** @param list<string> $roles @param list<string> $permissions */
    private function principalFrom(User $user, array $roles, array $permissions): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($user->id(), $user->isAuthenticated(), $roles, $permissions, 'user-test-snapshot');
    }
}
