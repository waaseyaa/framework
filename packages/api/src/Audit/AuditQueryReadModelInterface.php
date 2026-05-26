<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Audit;

/**
 * Read-model contract for the JSON:API audit events endpoint (T-M).
 *
 * Lives in `packages/api` (L4) — consumes audit-side L0 contracts via
 * `ApiAuditQueryAdapter`. All DTOs are api-local so the controller has
 * no dependency on `Waaseyaa\Audit\*` types.
 *
 * Dead-code guard (FR-013): removing the `singleton()` binding for this
 * interface in `ApiServiceProvider::register()` causes `OcapAuditEndpointTest`
 * to receive an empty `{data: [], meta: {total: 0, limit: 50, offset: 0}}`
 * response instead of the seeded events, causing the `count(data)` assertion
 * to fail. The test is the guard.
 *
 * @api
 */
interface AuditQueryReadModelInterface
{
    /**
     * @return iterable<AuditEventResource>
     */
    public function findBy(AuditQueryDto $query): iterable;

    public function count(AuditQueryDto $query): int;
}
