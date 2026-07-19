<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/**
 * @api
 *
 * Raised at definition-validation time (boot) when a backend declares it
 * cannot satisfy a query for a given field.
 *
 * The flow is:
 *   1. {@see \Waaseyaa\EntityStorage\Query\DefinitionValidator} calls
 *      {@see \Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway::supportsQuery()}.
 *   2. Backend returns false.
 *   3. Validator throws this exception with a precise reason.
 *
 * Boot fails immediately — NO silent fallback, NO runtime retry.
 *
 * Message format: "Backend {$backendId} cannot satisfy query on field {$fieldId}: {$reason}"
 */
final class UnsupportedQueryException extends \LogicException
{
    public readonly string $backendId;
    public readonly string $fieldId;
    public readonly string $reason;

    public function __construct(string $backendId, string $fieldId, string $reason)
    {
        $this->backendId = $backendId;
        $this->fieldId = $fieldId;
        $this->reason = $reason;

        parent::__construct(
            sprintf(
                'Backend %s cannot satisfy query on field %s: %s',
                $backendId,
                $fieldId,
                $reason,
            ),
        );
    }
}
