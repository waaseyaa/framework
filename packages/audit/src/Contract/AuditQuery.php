<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

use Waaseyaa\Audit\Enum\AuditEventKind;

/**
 * Immutable query value object for fetching audit events.
 *
 * @api
 */
final readonly class AuditQuery
{
    /**
     * @param AuditEventKind[]|null $kinds
     */
    public function __construct(
        public readonly ?int $accountUid = null,
        public readonly ?string $entityType = null,
        public readonly ?string $entityUuid = null,
        public readonly ?array $kinds = null,
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly int $limit = 50,
        public readonly int $offset = 0,
    ) {}
}
