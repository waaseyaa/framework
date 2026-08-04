<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Server-authoritative session capability projection (#2177 F1 C1c
 * prerequisite): the host projects an explicit, bounded allowlist of
 * permission identifiers through the resolved principal's hasPermission()
 * into the admin-surface session payload.
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostCapabilitiesTest extends TestCase
{
    /**
     * @param array<string, bool> $permissions granted permissions beyond the admin permission
     */
    private function principalWith(array $permissions): AuthorizationPrincipalInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('getRoles')->willReturn(['administrator']);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $permission === 'administer content'
                || ($permissions[$permission] ?? false),
        );

        return $account;
    }

    /**
     * @param list<string> $capabilityAllowlist
     */
    private function host(array $capabilityAllowlist): GenericAdminSurfaceHost
    {
        return new GenericAdminSurfaceHost(
            $this->createMock(EntityTypeManagerInterface::class),
            capabilityAllowlist: $capabilityAllowlist,
        );
    }

    private function sessionRequest(AuthorizationPrincipalInterface $account): Request
    {
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', $account);

        return $request;
    }

    #[Test]
    public function projects_allowlisted_capabilities_as_booleans_from_the_principal(): void
    {
        $host = $this->host(['mcp.approval.view', 'mcp.approval.decide']);
        $account = $this->principalWith(['mcp.approval.view' => true]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame(
            ['mcp.approval.decide' => false, 'mcp.approval.view' => true],
            $session->capabilities,
        );
    }

    #[Test]
    public function empty_allowlist_projects_no_capabilities(): void
    {
        $host = $this->host([]);
        $account = $this->principalWith(['mcp.approval.view' => true, 'mcp.approval.decide' => true]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame([], $session->capabilities);
    }

    #[Test]
    public function granted_permissions_outside_the_allowlist_are_never_projected(): void
    {
        $host = $this->host(['mcp.approval.view']);
        $account = $this->principalWith([
            'mcp.approval.view' => true,
            'mcp.approval.decide' => true,
            'administer users' => true,
        ]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame(['mcp.approval.view' => true], $session->capabilities);
    }

    #[Test]
    public function principal_is_queried_only_for_admin_and_allowlisted_permissions(): void
    {
        $queried = [];
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('getRoles')->willReturn(['administrator']);
        $account->method('hasPermission')->willReturnCallback(
            static function (string $permission) use (&$queried): bool {
                $queried[] = $permission;

                return true;
            },
        );

        $host = $this->host(['mcp.approval.view']);
        $host->resolveSession($this->sessionRequest($account));

        $this->assertSame([], array_diff(
            array_unique($queried),
            ['administer content', 'mcp.approval.view'],
        ), 'resolveSession() must not enumerate permissions beyond the admin gate and the allowlist.');
    }

    #[Test]
    public function roles_alone_never_grant_a_capability(): void
    {
        $host = $this->host(['mcp.approval.decide']);
        // Principal carries the administrator role but hasPermission() only
        // grants the admin gate permission — the projection must stay false.
        $account = $this->principalWith([]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame(['mcp.approval.decide' => false], $session->capabilities);
    }

    #[Test]
    public function allowlist_is_deduplicated_and_deterministically_sorted(): void
    {
        $host = $this->host(['mcp.approval.view', 'mcp.approval.decide', 'mcp.approval.view']);
        $account = $this->principalWith(['mcp.approval.view' => true]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame(
            ['mcp.approval.decide', 'mcp.approval.view'],
            array_keys($session->capabilities),
        );
    }

    #[Test]
    public function allowlist_accepts_space_separated_permission_identifiers(): void
    {
        $host = $this->host(['administer users']);
        $account = $this->principalWith(['administer users' => true]);

        $session = $host->resolveSession($this->sessionRequest($account));

        $this->assertNotNull($session);
        $this->assertSame(['administer users' => true], $session->capabilities);
    }

    #[Test]
    public function non_string_allowlist_entry_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->host(['mcp.approval.view', 7]);
    }

    #[Test]
    public function empty_string_allowlist_entry_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->host(['']);
    }

    #[Test]
    public function control_characters_in_allowlist_entry_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->host(["mcp.approval\nview"]);
    }

    #[Test]
    public function invalid_allowlist_entry_value_is_never_echoed_in_the_rejection(): void
    {
        // Configuration can be malformed with secret-like input (a credential
        // pasted into the wrong key). The rejection must describe the
        // constraint only — never interpolate the offending value.
        $sentinel = 'hunter2-XYZZY-t0psecret!';

        try {
            $this->host([$sentinel]);
            $this->fail('Expected InvalidArgumentException for an invalid allowlist entry.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString($sentinel, $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
            $this->assertStringNotContainsString('XYZZY', $e->getMessage());
        }
    }

    #[Test]
    public function overlong_allowlist_entry_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->host([str_repeat('a', GenericAdminSurfaceHost::CAPABILITY_IDENTIFIER_MAX_LENGTH + 1)]);
    }

    #[Test]
    public function oversized_allowlist_is_rejected(): void
    {
        $allowlist = [];
        for ($i = 0; $i <= GenericAdminSurfaceHost::CAPABILITY_ALLOWLIST_MAX; $i++) {
            $allowlist[] = 'permission.' . $i;
        }

        $this->expectException(\InvalidArgumentException::class);

        $this->host($allowlist);
    }

    #[Test]
    public function allowlist_at_the_cap_is_accepted(): void
    {
        $allowlist = [];
        for ($i = 0; $i < GenericAdminSurfaceHost::CAPABILITY_ALLOWLIST_MAX; $i++) {
            $allowlist[] = 'permission.' . $i;
        }

        $session = $this->host($allowlist)->resolveSession(
            $this->sessionRequest($this->principalWith([])),
        );

        $this->assertNotNull($session);
        $this->assertCount(GenericAdminSurfaceHost::CAPABILITY_ALLOWLIST_MAX, $session->capabilities);
    }
}
