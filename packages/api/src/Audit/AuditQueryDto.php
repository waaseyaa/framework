<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Audit;

/**
 * Immutable query value object for the JSON:API audit endpoint (api-local).
 *
 * Mirrors `Waaseyaa\Audit\Contract\AuditQuery` but lives in L4 so the
 * controller has no coupling to L0 audit types. The `ApiAuditQueryAdapter`
 * translates between the two.
 *
 * @api
 */
final readonly class AuditQueryDto
{
    /**
     * @param string[]|null $kinds Comma-list of AuditEventKind values (e.g. ['entity.read', 'entity.write']).
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
