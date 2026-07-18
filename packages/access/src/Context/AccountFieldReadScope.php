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
final class AccountFieldReadScope implements AccountFieldReadScopeInterface
{
    /** @var list<AuthorizationPrincipalInterface> */
    private array $mainStack = [];

    /** @var \WeakMap<\Fiber, list<AuthorizationPrincipalInterface>> */
    private \WeakMap $fiberStacks;

    public function __construct()
    {
        $this->fiberStacks = new \WeakMap();
    }

    public function current(): ?AuthorizationPrincipalInterface
    {
        $stack = $this->stack();

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    public function run(AuthorizationPrincipalInterface $principal, callable $callback): mixed
    {
        $fiber = \Fiber::getCurrent();
        $stack = $this->stack();
        $stack[] = $principal;
        $this->replaceStack($fiber, $stack);

        try {
            return $callback();
        } finally {
            $stack = $this->stack();
            array_pop($stack);
            $this->replaceStack($fiber, $stack);
        }
    }

    /** @return list<AuthorizationPrincipalInterface> */
    private function stack(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainStack;
        }

        return $this->fiberStacks[$fiber] ?? [];
    }

    /** @param list<AuthorizationPrincipalInterface> $stack */
    private function replaceStack(?\Fiber $fiber, array $stack): void
    {
        if ($fiber === null) {
            $this->mainStack = $stack;

            return;
        }
        if ($stack === []) {
            unset($this->fiberStacks[$fiber]);

            return;
        }
        $this->fiberStacks[$fiber] = $stack;
    }
}
