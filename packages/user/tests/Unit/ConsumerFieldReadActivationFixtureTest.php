<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

final class ConsumerFieldReadActivationFixtureTest extends TestCase
{
    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        $this->scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new UserAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            $handler->checkProtectedFieldRead(...),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    public function test_nameless_user_without_context_does_not_yield_mail(): void
    {
        $user = new User(['mail' => 'elder@example.test']);

        $this->expectException(FieldReadDenied::class);
        $user->get('mail');
    }

    public function test_protected_name_distinguishes_missing_context_from_denial(): void
    {
        $user = new User(['uid' => 5, 'name' => 'Member', 'status' => 1]);

        try {
            $user->getName();
            self::fail('A Protected read without context was accepted.');
        } catch (MissingFieldReadContext) {
        }

        $viewer = new AuthorizationPrincipal(9, true, ['member'], [], 'claims-1');
        $this->expectException(FieldReadDenied::class);
        $this->scope->run($viewer, static fn(): string => $user->getName());
    }

    public function test_authorization_input_mutation_invalidates_the_cached_name_decision(): void
    {
        $user = new User(['uid' => 5, 'name' => 'Member', 'status' => 1]);
        $viewer = new AuthorizationPrincipal(9, true, ['member'], ['access user profiles'], 'claims-1');

        $this->scope->run($viewer, static function () use ($user): void {
            self::assertSame('Member', $user->getName());
            $user->set('status', 0);
            try {
                $user->getName();
                self::fail('A decision cached before status mutation was reused.');
            } catch (FieldReadDenied) {
            }
        });
    }

    public function test_status_is_readable_by_self_and_admin_but_not_an_ordinary_viewer(): void
    {
        $user = new User(['uid' => 5, 'status' => 0]);
        $self = new AuthorizationPrincipal(5, true, ['member'], [], 'self-claims');
        $admin = new AuthorizationPrincipal(9, true, ['member'], ['administer users'], 'admin-claims');
        $viewer = new AuthorizationPrincipal(10, true, ['member'], ['access user profiles'], 'viewer-claims');

        self::assertSame(0, $this->scope->run($self, static fn(): mixed => $user->get('status')));
        self::assertSame(0, $this->scope->run($admin, static fn(): mixed => $user->get('status')));
        $this->expectException(FieldReadDenied::class);
        $this->scope->run($viewer, static fn(): mixed => $user->get('status'));
    }
}
