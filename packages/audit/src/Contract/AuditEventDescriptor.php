<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

use Waaseyaa\Audit\Enum\AuditEventKind;

/**
 * Immutable value object describing a single audit event to record.
 *
 * @api
 */
final readonly class AuditEventDescriptor
{
    /**
     * @param array<string, mixed> $attributes  Freeform JSON-serialisable metadata.
     */
    public function __construct(
        public readonly AuditEventKind $kind,
        public readonly int $accountUid,
        public readonly string $subjectUri,
        public readonly string $outcome,
        public readonly string $severity,
        public readonly ?string $entityTypeId = null,
        public readonly ?string $entityUuid = null,
        public readonly array $attributes = [],
    ) {
        if (!in_array($outcome, ['allowed', 'denied', 'error'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid outcome "%s". Must be one of: allowed, denied, error.', $outcome),
            );
        }

        if (!in_array($severity, ['info', 'notice', 'warning'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid severity "%s". Must be one of: info, notice, warning.', $severity),
            );
        }
    }
}
