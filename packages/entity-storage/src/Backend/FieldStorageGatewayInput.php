<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Empty opaque invocation handle; construction alone grants no authority. @api */
final class FieldStorageGatewayInput
{
    public function __serialize(): array
    {
        throw new \LogicException('Field-storage gateway inputs cannot be serialized.');
    }
}
