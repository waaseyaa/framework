<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Concrete contract test that exercises the abstract suite against
 * {@see InMemoryTransport}.
 */
#[CoversNothing]
final class InMemoryTransportContractTest extends TransportContractTest
{
    protected function makeTransport(): TransportInterface
    {
        return new InMemoryTransport();
    }
}
