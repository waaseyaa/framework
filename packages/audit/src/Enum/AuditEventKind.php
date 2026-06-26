<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Enum;

/**
 * Canonical vocabulary of audit event kinds.
 *
 * 20 cases (originally 14, extended additively by
 * `versioned-blob-media-abstraction-01KSEFTJ` (+3),
 * `revision-audit-provenance-01KTWY5V` (+2), and
 * WP3 audit tamper-evidence verify (+1) per the §Out-of-band
 * downstream-amendment principle — additive, no removal).
 *
 * The {@see \Waaseyaa\Audit\Contract\AuditEventDescriptor} rejects
 * kinds outside this enum at construction time (FR-004).
 *
 * @api
 */
enum AuditEventKind: string
{
    case EntityRead      = 'entity.read';
    case EntityWrite     = 'entity.write';
    case EntityDelete    = 'entity.delete';
    case EntityExport    = 'entity.export';
    case AccessDenied    = 'access.denied';
    case ClassificationChange = 'classification.change';
    case RetentionPurge  = 'retention.purge';
    case RetentionRedact = 'retention.redact';
    case RetentionHold   = 'retention.hold';
    case AgentToolExecute = 'agent.tool_execute';
    case McpDispatch     = 'mcp.dispatch';
    case BroadcastPublish = 'broadcast.publish';
    case ApiRequest      = 'api.request';
    case AuditRetentionPruned = 'audit.retention_pruned';

    // --- Added by versioned-blob-media-abstraction-01KSEFTJ ---
    /** A new MediaVersion row was created (blob written or dedup-hit). */
    case MediaVersionCreated = 'media.version.created';
    /** A specific MediaVersion was accessed (read) via the API. */
    case MediaVersionRead    = 'media.version.read';
    /** A blob deduplication hit occurred on write (content-addressed match). */
    case MediaVersionDedupHit = 'media.version.dedup_hit';

    // --- Added by revision-audit-provenance-01KTWY5V (FR-006) ---
    /** The published-revision pointer moved to a revision (publish operation). */
    case RevisionPublish = 'revision.publish';
    /** The current-revision pointer moved back to a prior revision (revert operation). */
    case RevisionRevert = 'revision.revert';

    // --- Added by WP3 audit tamper-evidence verify ---
    /** The audit:verify command ran and checked the hash chain + checkpoints. */
    case AuditVerified = 'audit.verify';
}
