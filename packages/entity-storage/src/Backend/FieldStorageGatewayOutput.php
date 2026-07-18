<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Empty opaque result handle; only its issuing gateway may unwrap it. @api */
final class FieldStorageGatewayOutput
{
    public function __serialize(): array
    {
        throw new \LogicException('Field-storage gateway outputs cannot be serialized.');
    }
}
