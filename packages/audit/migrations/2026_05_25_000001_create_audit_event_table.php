<?php

declare(strict_types=1);

use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Materialises the audit schema by delegating to AuditEventSchemaHandler
 * (the single source of truth — L1-audit.md m1).
 *
 * AuditEventSchemaHandler::ensureSchema() is the authoritative definition
 * of the audit_event table (including actor_uid, row_hash, prev_hash),
 * the audit_retention_policy table, the audit_checkpoint table, and the
 * genesis anchor. Delegating here ensures that a standalone `migrate` run
 * produces byte-identical schema to the AuditServiceProvider::boot() path,
 * eliminating the stale hand-maintained CREATE TABLE that previously lacked
 * actor_uid and the tamper-evidence chain columns the AuditEventWriter needs.
 *
 * Single-table — NOT two-axis. Audit events are immutable historical facts
 * and are never revised or translated (C-001).
 *
 * Idempotent: AuditEventSchemaHandler uses CREATE TABLE IF NOT EXISTS and
 * CREATE INDEX IF NOT EXISTS throughout.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        // Single source of truth for the audit schema (L1-audit.md m1): delegate
        // to the runtime AuditEventSchemaHandler that AuditServiceProvider::boot()
        // uses, so a standalone `migrate` produces byte-identical schema to the
        // boot path — including actor_uid / row_hash / prev_hash, which the
        // AuditEventWriter always inserts. A hand-maintained CREATE TABLE here was
        // a stale second definition that drifted from the writer's expectations.
        $database = new DBALDatabase($schema->getConnection());
        (new AuditEventSchemaHandler($database))->ensureSchema();
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping tables is version-dependent; left as no-op.
    }
};
