<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Audit\AuditQueryDto;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;

/**
 * Read-only HTTP controller for the OCAP audit events query endpoint (T-M).
 *
 * Route: `GET /api/audit/events` — gated by `_role: admin` at the route
 * level (BuiltinRouteRegistrar). The controller does NOT re-check role (NFR-001).
 *
 * Query parameters:
 *   - `page[limit]`    int  default 50, max 500
 *   - `page[offset]`   int  default 0
 *   - `filter[account]` int  account UID
 *   - `filter[entity]`  string  `type:uuid` (split on first colon)
 *   - `filter[kind]`    string  comma-separated AuditEventKind values
 *   - `filter[from]`   ISO-8601 datetime
 *   - `filter[to]`     ISO-8601 datetime
 *
 * @api
 */
final class AuditQueryController
{
    public function __construct(
        private readonly ?AuditQueryReadModelInterface $readModel = null,
    ) {}

    /**
     * `GET /api/audit/events`
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function index(Request $request): array
    {
        if ($this->readModel === null) {
            return $this->emptyShape(50, 0);
        }

        // Use all() for bracket-style params (page[limit], filter[kind], etc.)
        // — get() returns string|null, all($key) returns array.
        /** @var array<string, mixed> $pageParams */
        $pageParams = $request->query->all('page');
        /** @var array<string, mixed> $filterParams */
        $filterParams = $request->query->all('filter');

        $limit  = min(500, max(1, (int) ($pageParams['limit'] ?? 50)));
        $offset = max(0, (int) ($pageParams['offset'] ?? 0));

        $accountUid  = null;
        $entityType  = null;
        $entityUuid  = null;
        $kinds       = null;
        $from        = null;
        $to          = null;

        $filterAccount = $filterParams['account'] ?? null;
        if (is_scalar($filterAccount) && (string) $filterAccount !== '') {
            $accountUid = (int) $filterAccount;
        }

        $filterEntity = $filterParams['entity'] ?? null;
        if (is_string($filterEntity) && $filterEntity !== '') {
            // Split on first colon: "node:uuid-here" → type=node, uuid=uuid-here.
            $colonPos = strpos($filterEntity, ':');
            if ($colonPos !== false) {
                $entityType = substr($filterEntity, 0, $colonPos);
                $entityUuid = substr($filterEntity, $colonPos + 1);
            } else {
                $entityType = $filterEntity;
            }
        }

        $filterKind = $filterParams['kind'] ?? null;
        if (is_string($filterKind) && $filterKind !== '') {
            $kinds = array_values(array_filter(array_map('trim', explode(',', $filterKind)), static fn(string $s): bool => $s !== ''));
            if ($kinds === []) {
                $kinds = null;
            }
        }

        $filterFrom = $filterParams['from'] ?? null;
        if (is_string($filterFrom) && $filterFrom !== '') {
            try {
                $from = new \DateTimeImmutable($filterFrom);
            } catch (\Throwable) {
                // Invalid date — ignore, treat as unfiltered.
            }
        }

        $filterTo = $filterParams['to'] ?? null;
        if (is_string($filterTo) && $filterTo !== '') {
            try {
                $to = new \DateTimeImmutable($filterTo);
            } catch (\Throwable) {
                // Invalid date — ignore, treat as unfiltered.
            }
        }

        $dto = new AuditQueryDto(
            accountUid: $accountUid,
            entityType: $entityType,
            entityUuid: $entityUuid,
            kinds: $kinds,
            from: $from,
            to: $to,
            limit: $limit,
            offset: $offset,
        );

        $total = $this->readModel->count($dto);
        $resources = [];
        foreach ($this->readModel->findBy($dto) as $resource) {
            $resources[] = $resource->toArray();
        }

        return [
            'data' => $resources,
            'meta' => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    private function emptyShape(int $limit, int $offset): array
    {
        return [
            'data' => [],
            'meta' => [
                'total'  => 0,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }
}
