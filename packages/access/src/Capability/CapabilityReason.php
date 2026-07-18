<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** @api */
enum CapabilityReason: string
{
    case PersistenceSnapshot = 'persistence_snapshot';
    case CredentialVerification = 'credential_verification';
    case SessionBootstrap = 'session_bootstrap';
    case MailDelivery = 'mail_delivery';
    case MigrationImport = 'migration_import';
    case MaintenanceCli = 'maintenance_cli';
    case SystemJob = 'system_job';
    case AdminTooling = 'admin_tooling';
    case StrictAuditProjection = 'strict_audit_projection';
    case TestFixture = 'test_fixture';
}
