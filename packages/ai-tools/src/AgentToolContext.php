<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;

/**
 * Immutable execution context passed to every {@see AgentToolInterface::execute()} call.
 *
 * Bundles the three cross-cutting concerns that every governed tool needs:
 *  - The caller identity ({@see $account}) for per-record access checks.
 *  - The {@see $entityAccessHandler} for entity-level and field-level OCAP checks.
 *  - The current {@see $agentRunId} for audit lineage (NFR-003 / DIR-004).
 *
 * Tools that carry `#[Capability(governedData: false)]` receive this context
 * but are not required to invoke the access handler (they expose only
 * application metadata, never user-data records).
 *
 * @api
 */
final readonly class AgentToolContext
{
    public function __construct(
        public AccountInterface $account,
        public EntityAccessHandler $entityAccessHandler,
        public ?string $agentRunId,
    ) {}
}
