<?php

declare(strict_types=1);

namespace Aurora\User\Tests\Unit;

use Aurora\User\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function testConstructWithDefaults(): void
    {
        $role = new Role(id: 'editor', label: 'Editor');

        $this->assertSame('editor', $role->id);
        $this->assertSame('Editor', $role->label);
        $this->assertSame([], $role->permissions);
        $this->assertSame(0, $role->weight);
    }

    public function testConstructWithAllParameters(): void
    {
        $permissions = ['edit content', 'delete content'];
        $role = new Role(
            id: 'admin',
            label: 'Administrator',
            permissions: $permissions,
            weight: 10,
        );

        $this->assertSame('admin', $role->id);
        $this->assertSame('Administrator', $role->label);
        $this->assertSame($permissions, $role->permissions);
        $this->assertSame(10, $role->weight);
    }

    public function testIsReadonly(): void
    {
        $role = new Role(id: 'viewer', label: 'Viewer');

        // Role is readonly — verify the class is indeed marked readonly.
        $reflection = new \ReflectionClass($role);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(Role::class);
        $this->assertTrue($reflection->isFinal());
    }
}
