<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

/** Fixed write-audit projection; no caller-selected field surface. @internal */
final readonly class EntityWriteAuditSubject
{
    public function __construct(
        public int|string|null $authorId,
        public ?string $tenantId,
    ) {}
}
