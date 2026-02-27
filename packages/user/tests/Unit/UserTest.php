<?php

declare(strict_types=1);

namespace Aurora\User\Tests\Unit;

use Aurora\Access\AccountInterface;
use Aurora\Entity\ContentEntityBase;
use Aurora\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
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
        $this->assertSame('alice', $user->label());
    }

    // -----------------------------------------------------------------
    // Name
    // -----------------------------------------------------------------

    public function testGetSetName(): void
    {
        $user = new User();
        $this->assertSame('', $user->getName());

        $user->setName('bob');
        $this->assertSame('bob', $user->getName());
    }

    public function testNameViaConstructor(): void
    {
        $user = new User(['name' => 'charlie']);
        $this->assertSame('charlie', $user->getName());
    }

    // -----------------------------------------------------------------
    // Email
    // -----------------------------------------------------------------

    public function testGetSetEmail(): void
    {
        $user = new User();
        $this->assertSame('', $user->getEmail());

        $user->setEmail('alice@example.com');
        $this->assertSame('alice@example.com', $user->getEmail());
    }

    public function testEmailViaConstructor(): void
    {
        $user = new User(['mail' => 'bob@example.com']);
        $this->assertSame('bob@example.com', $user->getEmail());
    }

    // -----------------------------------------------------------------
    // Password hashing and verification
    // -----------------------------------------------------------------

    public function testSetRawPasswordHashesAndStores(): void
    {
        $user = new User();
        $user->setRawPassword('secret123');

        $hash = $user->getPassword();
        $this->assertNotEmpty($hash);
        $this->assertNotSame('secret123', $hash);
        // The hash should be verifiable.
        $this->assertTrue(password_verify('secret123', $hash));
    }

    public function testCheckPasswordReturnsTrueForCorrect(): void
    {
        $user = new User();
        $user->setRawPassword('p@ssw0rd');

        $this->assertTrue($user->checkPassword('p@ssw0rd'));
    }

    public function testCheckPasswordReturnsFalseForWrong(): void
    {
        $user = new User();
        $user->setRawPassword('correct-horse');

        $this->assertFalse($user->checkPassword('wrong-battery'));
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

        $this->assertSame($hash, $user->getPassword());
        $this->assertTrue($user->checkPassword('manual'));
    }

    // -----------------------------------------------------------------
    // Roles
    // -----------------------------------------------------------------

    public function testDefaultRolesAreEmpty(): void
    {
        $user = new User();
        $this->assertSame([], $user->getRoles());
    }

    public function testSetRoles(): void
    {
        $user = new User();
        $user->setRoles(['editor', 'admin']);
        $this->assertSame(['editor', 'admin'], $user->getRoles());
    }

    public function testRolesViaConstructor(): void
    {
        $user = new User(['roles' => ['authenticated', 'editor']]);
        $this->assertSame(['authenticated', 'editor'], $user->getRoles());
    }

    public function testAddRole(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $user->addRole('editor');
        $this->assertSame(['authenticated', 'editor'], $user->getRoles());
    }

    public function testAddRoleDoesNotDuplicate(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $user->addRole('authenticated');
        $this->assertSame(['authenticated'], $user->getRoles());
    }

    public function testRemoveRole(): void
    {
        $user = new User(['roles' => ['authenticated', 'editor', 'admin']]);
        $user->removeRole('editor');
        $this->assertSame(['authenticated', 'admin'], $user->getRoles());
    }

    public function testRemoveNonExistentRoleIsNoOp(): void
    {
        $user = new User(['roles' => ['authenticated']]);
        $user->removeRole('nonexistent');
        $this->assertSame(['authenticated'], $user->getRoles());
    }

    // -----------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------

    public function testDefaultPermissionsAreEmpty(): void
    {
        $user = new User();
        $this->assertFalse($user->hasPermission('anything'));
    }

    public function testHasPermission(): void
    {
        $user = new User(['permissions' => ['edit content', 'view content']]);
        $this->assertTrue($user->hasPermission('edit content'));
        $this->assertTrue($user->hasPermission('view content'));
        $this->assertFalse($user->hasPermission('delete content'));
    }

    public function testSetPermissions(): void
    {
        $user = new User();
        $user->setPermissions(['administer site']);
        $this->assertTrue($user->hasPermission('administer site'));
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

    // -----------------------------------------------------------------
    // Active / blocked status
    // -----------------------------------------------------------------

    public function testIsActiveByDefault(): void
    {
        $user = new User();
        $this->assertTrue($user->isActive());
    }

    public function testSetActiveToFalse(): void
    {
        $user = new User();
        $user->setActive(false);
        $this->assertFalse($user->isActive());
    }

    public function testSetActiveToTrue(): void
    {
        $user = new User(['status' => 0]);
        $this->assertFalse($user->isActive());

        $user->setActive(true);
        $this->assertTrue($user->isActive());
    }

    // -----------------------------------------------------------------
    // toArray
    // -----------------------------------------------------------------

    public function testToArrayContainsAllValues(): void
    {
        $user = new User([
            'uid' => 5,
            'name' => 'dave',
            'mail' => 'dave@example.com',
            'roles' => ['admin'],
            'permissions' => ['do stuff'],
            'status' => 1,
        ]);

        $array = $user->toArray();
        $this->assertSame(5, $array['uid']);
        $this->assertSame('dave', $array['name']);
        $this->assertSame('dave@example.com', $array['mail']);
        $this->assertSame(['admin'], $array['roles']);
        $this->assertArrayHasKey('uuid', $array);
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
        $this->assertSame($user, $user->addRole('r'));
        $this->assertSame($user, $user->removeRole('r'));
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
}
