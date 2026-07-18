<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;

/** Backend-only unwrapped invocation. Obtain only through FieldStorageGatewayRole. @api */
final readonly class FieldStorageGatewayInvocation
{
    public function __construct(
        public FieldStorageGatewayOperation $operation,
        public ?EntityInterface $entity,
        public ?FieldDefinition $field,
        public mixed $value,
        public ?EntityQuery $query,
    ) {}

    public function __serialize(): array
    {
        throw new \LogicException('Field-storage invocations cannot be serialized.');
    }
}
