<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

/**
 * Exact ledger migration ids whose current legacy `down()` bodies are supported
 * reverse plans (#2731).
 *
 * Historical migration files stay checksum-bound: do not edit them to opt in.
 * Forward-only no-op `down()` overrides must stay out of this catalogue.
 *
 * @api
 */
final class LegacyReversePlanCatalog
{
    /**
     * @var array<string, true>
     */
    private const SUPPORTED = [
        'waaseyaa/ai-agent:2026_05_18_000001_create_agent_run' => true,
        'waaseyaa/ai-observability:2026_04_14_000001_create_trace_span_table' => true,
        'waaseyaa/media:2026_05_25_000005_create_media_version_table' => true,
        'waaseyaa/migration:2026_05_13_000001_create_migration_id_map' => true,
        'waaseyaa/migration:2026_05_13_000002_create_migration_run_state' => true,
        'waaseyaa/notification:2026_04_24_000001_create_notification_tables' => true,
        'waaseyaa/queue:2026_04_24_000001_create_queue_tables' => true,
        'waaseyaa/scheduler:2026_04_24_000001_create_scheduler_tables' => true,
    ];

    public static function allows(string $migrationId): bool
    {
        return isset(self::SUPPORTED[$migrationId]);
    }
}
