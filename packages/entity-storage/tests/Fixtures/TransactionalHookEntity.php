<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

/** Host override with transactional related writes and an optional refusal. */
final class TransactionalHookEntity extends TestStorageEntity
{
    /** @var list<string> */
    public array $hookCalls = [];

    /** @var null|\Closure(string): void */
    public ?\Closure $onPostHook = null;

    public function postSave(bool $isNew): void
    {
        $this->hookCalls[] = 'save';
        ($this->onPostHook)?->__invoke('save');
    }

    public function postDelete(): void
    {
        $this->hookCalls[] = 'delete';
        ($this->onPostHook)?->__invoke('delete');
    }
}
