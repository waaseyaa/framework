<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Contract;

use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;

final class InMemoryStorageDriverContractTest extends EntityStorageDriverContractTest
{
    protected function createDriver(): EntityStorageDriverInterface
    {
        return new InMemoryStorageDriver();
    }
}
