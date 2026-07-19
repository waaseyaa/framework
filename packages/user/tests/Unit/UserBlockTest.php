<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\User\UserBlock;
use Waaseyaa\User\UserBlockAccessPolicy;

#[CoversClass(UserBlock::class)]
final class UserBlockTest extends TestCase
{
    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        $this->scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new UserBlockAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($this->scope, $handler->checkProtectedFieldRead(...)));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function creates_with_required_fields(): void
    {
        $block = new UserBlock(['blocker_id' => 1, 'blocked_id' => 2]);
        $admin = new AuthorizationPrincipal(9, true, ['member'], ['administer content'], 'admin-claims');
        $this->scope->run($admin, static function () use ($block): void {
            self::assertSame(1, (int) $block->get('blocker_id'));
            self::assertSame(2, (int) $block->get('blocked_id'));
            self::assertNotNull($block->get('created_at'));
        });
    }

    #[Test]
    public function uses_provided_created_at(): void
    {
        $block = new UserBlock(['blocker_id' => 1, 'blocked_id' => 2, 'created_at' => 1000]);
        $admin = new AuthorizationPrincipal(9, true, ['member'], ['administer content'], 'admin-claims');
        self::assertSame(1000, (int) $this->scope->run($admin, static fn(): mixed => $block->get('created_at')));
    }

    #[Test]
    public function requires_blocker_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blocker_id');
        new UserBlock(['blocked_id' => 2]);
    }

    #[Test]
    public function requires_blocked_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blocked_id');
        new UserBlock(['blocker_id' => 1]);
    }

    #[Test]
    public function rejects_self_block(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot block yourself');
        new UserBlock(['blocker_id' => 1, 'blocked_id' => 1]);
    }
}
