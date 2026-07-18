<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Support;

use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayInput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOperation;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOutput;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayRole;

/** Test backend behavior implemented directly through the V2 opaque invocation contract. */
trait V2GatewayTestBackendTrait
{
    public function fingerprint(): string
    {
        return hash('sha256', static::class . ':' . $this->id());
    }

    public function invoke(FieldStorageGatewayRole $gateway, FieldStorageGatewayInput $input): FieldStorageGatewayOutput
    {
        $call = $gateway->unwrap($input, $this);
        switch ($call->operation) {
            case FieldStorageGatewayOperation::Read:
                $value = $this->read(
                    $call->entity ?? throw new \LogicException('Test read requires an entity.'),
                    $call->field ?? throw new \LogicException('Test read requires a field.'),
                );
                break;
            case FieldStorageGatewayOperation::Write:
                $this->write(
                    $call->entity ?? throw new \LogicException('Test write requires an entity.'),
                    $call->field ?? throw new \LogicException('Test write requires a field.'),
                    $call->value,
                );
                $value = null;
                break;
            case FieldStorageGatewayOperation::Delete:
                $this->delete($call->entity ?? throw new \LogicException('Test delete requires an entity.'));
                $value = null;
                break;
            case FieldStorageGatewayOperation::SupportsQuery:
                $value = $this->supportsQuery(
                    $call->field ?? throw new \LogicException('Test query support requires a field.'),
                    $call->query ?? throw new \LogicException('Test query support requires a query.'),
                );
                break;
        }

        return $gateway->complete($input, $this, $value);
    }
}
