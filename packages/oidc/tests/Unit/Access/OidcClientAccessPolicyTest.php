<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Oidc\Access\OidcClientAccessPolicy;
use Waaseyaa\Oidc\Entity\OidcClient;

#[CoversClass(OidcClientAccessPolicy::class)]
final class OidcClientAccessPolicyTest extends TestCase
{
    private OidcClientAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new OidcClientAccessPolicy();
    }

    public function testImplementsEntityAccessPolicy(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testImplementsFieldAccessPolicy(): void
    {
        $this->assertInstanceOf(FieldAccessPolicyInterface::class, $this->policy);
    }

    public function testAppliesToOidcClient(): void
    {
        $this->assertTrue($this->policy->appliesTo('oidc_client'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo('node'));
    }

    // ---- Entity-level access ----

    public function testAdminCanView(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'view', $this->admin());
        $this->assertTrue($result->isAllowed());
    }

    public function testAdminCanUpdate(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'update', $this->admin());
        $this->assertTrue($result->isAllowed());
    }

    public function testAdminCanDelete(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'delete', $this->admin());
        $this->assertTrue($result->isAllowed());
    }

    public function testAdminCanCreate(): void
    {
        $result = $this->policy->createAccess('oidc_client', '', $this->admin());
        $this->assertTrue($result->isAllowed());
    }

    public function testNonAdminCannotView(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'view', $this->nonAdmin());
        $this->assertFalse($result->isAllowed());
    }

    public function testNonAdminCannotUpdate(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'update', $this->nonAdmin());
        $this->assertFalse($result->isAllowed());
    }

    public function testNonAdminCannotDelete(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'delete', $this->nonAdmin());
        $this->assertFalse($result->isAllowed());
    }

    public function testNonAdminCannotCreate(): void
    {
        $result = $this->policy->createAccess('oidc_client', '', $this->nonAdmin());
        $this->assertFalse($result->isAllowed());
    }

    public function testAnonymousCannotView(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->access($client, 'view', $this->anonymous());
        $this->assertFalse($result->isAllowed());
    }

    // ---- Field-level access ----

    public function testClientSecretHashIsForbiddenForAdminView(): void
    {
        // Even admins cannot read the hash through the API — they can rotate, not read.
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->fieldAccess($client, 'client_secret_hash', 'view', $this->admin());
        $this->assertTrue($result->isForbidden());
    }

    public function testClientSecretHashIsForbiddenForEveryone(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $result = $this->policy->fieldAccess($client, 'client_secret_hash', 'view', $this->nonAdmin());
        $this->assertTrue($result->isForbidden());
    }

    public function testClientIdEditForbiddenOnExistingClient(): void
    {
        // client_id is an immutable public identifier per OIDC spec.
        $client = new OidcClient(['id' => 1, 'client_id' => 'minoo-web']);
        $result = $this->policy->fieldAccess($client, 'client_id', 'edit', $this->admin());
        $this->assertTrue($result->isForbidden());
    }

    public function testClientIdEditAllowedOnNewClient(): void
    {
        // New clients need client_id to be set.
        $client = new OidcClient(['client_id' => 'minoo-web']);
        $this->assertTrue($client->isNew());
        $result = $this->policy->fieldAccess($client, 'client_id', 'edit', $this->admin());
        $this->assertFalse($result->isForbidden());
    }

    public function testOtherFieldsOpenForView(): void
    {
        $client = new OidcClient(['client_id' => 'minoo-web']);
        foreach (['name', 'redirect_uris', 'scopes', 'grant_types', 'is_confidential'] as $field) {
            $result = $this->policy->fieldAccess($client, $field, 'view', $this->admin());
            $this->assertFalse(
                $result->isForbidden(),
                "Field '$field' view should not be forbidden for admin",
            );
        }
    }

    public function testOtherFieldsOpenForEdit(): void
    {
        $client = new OidcClient(['id' => 1, 'client_id' => 'minoo-web']);
        foreach (['name', 'redirect_uris', 'scopes', 'grant_types', 'is_confidential'] as $field) {
            $result = $this->policy->fieldAccess($client, $field, 'edit', $this->admin());
            $this->assertFalse(
                $result->isForbidden(),
                "Field '$field' edit should not be forbidden for admin",
            );
        }
    }

    // ---- Helpers ----

    private function admin(): AccountInterface
    {
        $mock = $this->createStub(AccountInterface::class);
        $mock->method('hasPermission')
            ->willReturnCallback(fn(string $p): bool => $p === 'administer oidc clients');
        $mock->method('id')->willReturn(1);
        return $mock;
    }

    private function nonAdmin(): AccountInterface
    {
        $mock = $this->createStub(AccountInterface::class);
        $mock->method('hasPermission')->willReturn(false);
        $mock->method('id')->willReturn(2);
        return $mock;
    }

    private function anonymous(): AccountInterface
    {
        $mock = $this->createStub(AccountInterface::class);
        $mock->method('hasPermission')->willReturn(false);
        $mock->method('id')->willReturn(0);
        $mock->method('isAuthenticated')->willReturn(false);
        return $mock;
    }
}
