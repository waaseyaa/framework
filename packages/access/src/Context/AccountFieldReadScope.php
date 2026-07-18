<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Stack-scoped account context. Child fibers start with an empty stack and
 * authority is always restored in finally.
 *
 * @api
 */
final class AccountFieldReadScope implements FastAccountFieldReadScopeInterface
{
    /** @var list<AccountFieldReadContext> */
    private array $mainStack = [];

    private ?AccountFieldReadContext $mainCurrent = null;

    /** @var \WeakMap<\Fiber, list<AccountFieldReadContext>> */
    private \WeakMap $fiberStacks;

    public function __construct()
    {
        $this->fiberStacks = new \WeakMap();
    }

    public function current(): ?AuthorizationPrincipalInterface
    {
        return $this->currentContext()?->principal;
    }

    public function currentContext(): ?AccountFieldReadContext
    {
        if (\Fiber::getCurrent() === null) {
            return $this->mainCurrent;
        }
        $stack = $this->stack();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    public function isCurrentContext(AccountFieldReadContext $context): bool
    {
        return $this->currentContext() === $context;
    }

    public function run(AuthorizationPrincipalInterface $principal, callable $callback): mixed
    {
        return $this->runWithGenerations($principal, 'unversioned', 'unversioned', $callback);
    }

    public function runWithGenerations(
        AuthorizationPrincipalInterface $principal,
        string $classificationGeneration,
        string $policyGeneration,
        callable $callback,
    ): mixed {
        $fiber = \Fiber::getCurrent();
        $stack = $this->stack();
        $context = new AccountFieldReadContext($principal, $classificationGeneration, $policyGeneration);
        $stack[] = $context;
        $this->replaceStack($fiber, $stack);

        try {
            return $callback();
        } finally {
            $stack = $this->stack();
            array_pop($stack);
            $this->replaceStack($fiber, $stack);
        }
    }

    /** @return list<AccountFieldReadContext> */
    private function stack(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainStack;
        }

        return $this->fiberStacks[$fiber] ?? [];
    }

    /** @param list<AccountFieldReadContext> $stack */
    private function replaceStack(?\Fiber $fiber, array $stack): void
    {
        if ($fiber === null) {
            $this->mainStack = $stack;
            $this->mainCurrent = $stack === [] ? null : $stack[array_key_last($stack)];

            return;
        }
        if ($stack === []) {
            unset($this->fiberStacks[$fiber]);

            return;
        }
        $this->fiberStacks[$fiber] = $stack;
    }
}
