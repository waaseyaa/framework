<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessStatus;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Entity\AgentRun;

#[CoversClass(AgentRunAccessPolicy::class)]
final class AgentRunAccessPolicyTest extends TestCase
{
    #[Test]
    public function appliesToAgentRunAndAuditLogEntityTypes(): void
    {
        $policy = new AgentRunAccessPolicy();

        self::assertTrue($policy->appliesTo('agent_run'));
        self::assertTrue($policy->appliesTo('agent_audit_log'));
        self::assertFalse($policy->appliesTo('node'));
    }

    #[Test]
    public function initiatorIsAllowedForAllOperations(): void
    {
        $policy = new AgentRunAccessPolicy();
        $run = $this->makeRun(accountId: 42);
        $initiator = $this->account(id: 42);

        foreach (['view', 'update', 'delete'] as $op) {
            $result = $policy->access($run, $op, $initiator);
            self::assertSame(
                AccessStatus::ALLOWED,
                $result->status,
                "Initiator must be allowed for $op",
            );
        }
    }

    #[Test]
    public function realEntityHandlerUsesTheCompiledOwnerSubjectWithoutAnOrdinaryProtectedRead(): void
    {
        $handler = new EntityAccessHandler([new AgentRunAccessPolicy()]);
        $run = $this->makeRun(accountId: 42);
        $owner = new AuthorizationPrincipal(42, true, ['authenticated'], [], 'claims-1');
        $stranger = new AuthorizationPrincipal(99, true, ['authenticated'], [], 'claims-1');

        self::assertTrue($handler->check($run, 'view', $owner)->isAllowed());
        self::assertTrue($handler->check($run, 'view', $stranger)->isForbidden());
    }

    #[Test]
    public function nonInitiatorWithoutBypassIsNeutral(): void
    {
        $policy = new AgentRunAccessPolicy();
        $run = $this->makeRun(accountId: 42);
        $stranger = $this->account(id: 99);

        $result = $policy->access($run, 'view', $stranger);

        self::assertSame(AccessStatus::NEUTRAL, $result->status);
    }

    #[Test]
    public function bypassOwnershipPermissionGrantsAccess(): void
    {
        $policy = new AgentRunAccessPolicy();
        $run = $this->makeRun(accountId: 42);
        $admin = $this->account(id: 99, permissions: ['agent.run.bypass_ownership']);

        $result = $policy->access($run, 'delete', $admin);

        self::assertSame(AccessStatus::ALLOWED, $result->status);
    }

    #[Test]
    public function anonymousAccountIsNeutral(): void
    {
        $policy = new AgentRunAccessPolicy();
        $run = $this->makeRun(accountId: 42);
        $anon = $this->account(id: 0, authenticated: false);

        $result = $policy->access($run, 'view', $anon);

        self::assertSame(AccessStatus::NEUTRAL, $result->status);
    }

    #[Test]
    public function createAccessRequiresAgentRunPermission(): void
    {
        $policy = new AgentRunAccessPolicy();

        $authed = $this->account(id: 1, permissions: ['agent.run']);
        $stranger = $this->account(id: 2);

        self::assertSame(
            AccessStatus::ALLOWED,
            $policy->createAccess('agent_run', '', $authed)->status,
        );
        self::assertSame(
            AccessStatus::NEUTRAL,
            $policy->createAccess('agent_run', '', $stranger)->status,
        );
    }

    #[Test]
    public function createAccessOnUnrelatedEntityTypeIsNeutral(): void
    {
        $policy = new AgentRunAccessPolicy();
        $account = $this->account(id: 1, permissions: ['agent.run']);

        self::assertSame(
            AccessStatus::NEUTRAL,
            $policy->createAccess('node', 'article', $account)->status,
        );
    }

    #[Test]
    public function fieldAccessIsNeutralOpenByDefault(): void
    {
        $policy = new AgentRunAccessPolicy();
        $run = $this->makeRun(accountId: 42);
        $account = $this->account(id: 42);

        // Open-by-default: Neutral = accessible at field level.
        $result = $policy->fieldAccess($run, 'transcript_json', 'view', $account);
        self::assertSame(AccessStatus::NEUTRAL, $result->status);
    }

    private function makeRun(int $accountId): AgentRun
    {
        return new AgentRun(['id' => 'run-' . $accountId, 'account_id' => $accountId, 'status' => 'queued']);
    }

    /**
     * @param list<string> $permissions
     */
    private function account(int $id, array $permissions = [], bool $authenticated = true): AccountInterface
    {
        return new class ($id, $permissions, $authenticated) implements AccountInterface {
            /**
             * @param list<string> $permissions
             */
            public function __construct(
                private readonly int $accountId,
                private readonly array $permissions,
                private readonly bool $authenticated,
            ) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, $this->permissions, strict: true);
            }

            public function getRoles(): array
            {
                return $this->authenticated ? ['authenticated'] : ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }
        };
    }
}
