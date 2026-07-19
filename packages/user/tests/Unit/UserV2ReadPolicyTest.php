<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserEntityReadPolicy;
use Waaseyaa\User\UserProtectedFieldReadPolicy;

#[CoversClass(UserEntityReadPolicy::class)]
#[CoversClass(UserProtectedFieldReadPolicy::class)]
final class UserV2ReadPolicyTest extends TestCase
{
    #[Test]
    public function policies_use_the_immutable_principal_v2_contracts(): void
    {
        self::assertInstanceOf(ProtectedEntityReadPolicyInterface::class, new UserEntityReadPolicy());
        self::assertInstanceOf(ProtectedFieldReadPolicyInterface::class, new UserProtectedFieldReadPolicy());
    }

    #[Test]
    public function discovered_legacy_policy_exposes_the_v2_policies_without_raw_entity_reads(): void
    {
        $provider = new UserAccessPolicy();

        self::assertInstanceOf(ProtectedReadPolicyProviderInterface::class, $provider);
        self::assertInstanceOf(ProtectedEntityReadPolicyInterface::class, $provider->protectedEntityReadPolicy());
        self::assertInstanceOf(ProtectedFieldReadPolicyInterface::class, $provider->protectedFieldReadPolicy());
    }

    #[Test]
    public function active_profile_and_name_are_visible_to_an_ordinary_profile_viewer(): void
    {
        $principal = $this->principal(9, ['access user profiles']);
        $structure = $this->structure(5);
        $subject = $this->subject(['status' => true]);

        self::assertTrue(new UserEntityReadPolicy()->access($principal, $structure, $subject, 'view')->isAllowed());
        self::assertTrue(new UserProtectedFieldReadPolicy()->access($principal, $structure, $subject, 'name')->isAllowed());
    }

    #[Test]
    public function inactive_profile_and_name_are_forbidden_to_self_and_ordinary_viewers(): void
    {
        $structure = $this->structure(5);
        $subject = $this->subject(['status' => false]);

        foreach ([$this->principal(5, ['access user profiles']), $this->principal(9, ['access user profiles'])] as $principal) {
            self::assertTrue(new UserEntityReadPolicy()->access($principal, $structure, $subject, 'view')->isForbidden());
            self::assertTrue(new UserProtectedFieldReadPolicy()->access($principal, $structure, $subject, 'name')->isForbidden());
        }
    }

    #[Test]
    public function administrator_can_view_an_inactive_profile_and_name(): void
    {
        $principal = $this->principal(9, ['administer users']);
        $structure = $this->structure(5);
        $subject = $this->subject(['status' => false]);

        self::assertTrue(new UserEntityReadPolicy()->access($principal, $structure, $subject, 'view')->isAllowed());
        self::assertTrue(new UserProtectedFieldReadPolicy()->access($principal, $structure, $subject, 'name')->isAllowed());
    }

    #[Test]
    public function direct_status_read_is_limited_to_self_or_administrator_without_subject_inputs(): void
    {
        $policy = new UserProtectedFieldReadPolicy();
        $structure = $this->structure(5);
        $emptySubject = $this->subject([]);

        self::assertTrue($policy->access($this->principal(5), $structure, $emptySubject, 'status')->isAllowed());
        self::assertTrue($policy->access($this->principal(9, ['administer users']), $structure, $emptySubject, 'status')->isAllowed());
        self::assertTrue($policy->access($this->principal(9, ['access user profiles']), $structure, $emptySubject, 'status')->isForbidden());
    }

    #[Test]
    public function exact_subject_maps_are_fail_closed(): void
    {
        $principal = $this->principal(9, ['access user profiles']);
        $structure = $this->structure(5);
        $policy = new UserProtectedFieldReadPolicy();

        self::assertTrue($policy->access($principal, $structure, $this->subject([]), 'name')->isForbidden());
        self::assertTrue($policy->access($principal, $structure, $this->subject(['status' => true, 'mail' => 'leak@example.test']), 'name')->isForbidden());
        self::assertTrue($policy->access($principal, $structure, $this->subject(['status' => true]), 'status')->isForbidden());
        self::assertTrue(new UserEntityReadPolicy()->access(
            $principal,
            $structure,
            $this->subject(['status' => true, 'roles' => ['administrator']]),
            'view',
        )->isForbidden());
        $admin = $this->principal(1, ['administer users']);
        $polluted = $this->subject(['status' => false, 'mail' => 'leak@example.test']);
        self::assertTrue($policy->access($admin, $structure, $polluted, 'name')->isForbidden());
        self::assertTrue(new UserEntityReadPolicy()->access($admin, $structure, $polluted, 'view')->isForbidden());
    }

    #[Test]
    public function internal_identity_fields_are_never_released_by_the_protected_policy(): void
    {
        $policy = new UserProtectedFieldReadPolicy();
        $admin = $this->principal(1, ['administer users']);

        foreach (['mail', 'pass', 'roles', 'permissions', 'email_verified', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step'] as $field) {
            self::assertTrue($policy->access($admin, $this->structure(5), $this->subject([]), $field)->isForbidden(), $field);
        }
    }

    /** @param list<string> $permissions */
    private function principal(int $id, array $permissions = []): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($id, true, ['member'], $permissions, 'claims-1');
    }

    private function structure(int $id): EntityStructure
    {
        return new EntityStructure(
            entityTypeId: 'user',
            bundleId: 'user',
            id: $id,
            fieldNames: ['uid', 'uuid', 'name', 'status', 'mail'],
        );
    }

    /** @param array<string, mixed> $values */
    private function subject(array $values): PolicySubjectViewInterface
    {
        return new class ($values) implements PolicySubjectViewInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values) {}

            public function fields(): array
            {
                return array_keys($this->values);
            }

            public function get(string $fieldName): mixed
            {
                return $this->values[$fieldName] ?? null;
            }
        };
    }
}
