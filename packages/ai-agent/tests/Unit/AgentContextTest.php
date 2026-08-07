<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentContext::class)]
final class AgentContextTest extends TestCase
{
    public function testConstruction(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(42);

        $context = new AgentContext(
            account: $account,
            parameters: ['title' => 'Hello World'],
            dryRun: false,
        );

        self::assertSame($account, $context->account);
        self::assertSame(['title' => 'Hello World'], $context->parameters);
        self::assertFalse($context->dryRun);
    }

    public function testDefaultValues(): void
    {
        $account = $this->createStub(AccountInterface::class);

        $context = new AgentContext(account: $account);

        self::assertSame([], $context->parameters);
        self::assertFalse($context->dryRun);
    }

    public function testDryRunFlag(): void
    {
        $account = $this->createStub(AccountInterface::class);

        $context = new AgentContext(
            account: $account,
            dryRun: true,
        );

        self::assertTrue($context->dryRun);
    }

    public function testIsReadonly(): void
    {
        $reflection = new \ReflectionClass(AgentContext::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
    }
}
