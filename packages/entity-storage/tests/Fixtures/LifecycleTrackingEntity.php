<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

/**
 * Test entity that records lifecycle hook calls for verification.
 */
final class LifecycleTrackingEntity extends TestStorageEntity
{
    /** @var list<string> */
    public array $hookLog = [];

    public function preSave(bool $isNew): void
    {
        $this->hookLog[] = 'preSave:' . ($isNew ? 'new' : 'update');
    }

    public function postSave(bool $isNew): void
    {
        $this->hookLog[] = 'postSave:' . ($isNew ? 'new' : 'update');
    }

    public function preDelete(): void
    {
        $this->hookLog[] = 'preDelete';
    }

    public function postDelete(): void
    {
        $this->hookLog[] = 'postDelete';
    }
}
