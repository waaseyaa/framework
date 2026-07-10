<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Enum;

/**
 * Canonical vocabulary of audit event kinds.
 *
 * 23 cases (originally 14, extended additively by
 * `versioned-blob-media-abstraction-01KSEFTJ` (+3),
 * `revision-audit-provenance-01KTWY5V` (+2),
 * WP3 audit tamper-evidence verify (+1),
 * WP4 audit fail-open marker+metric (#1792) (+1),
 * CW-v1 WP-1 (#1920) (+1), and
 * CW-v1 WP-2 task 2.5 (#1920) (+1) per the §Out-of-band
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

    // --- Added by WP4 audit fail-open marker+metric (#1792, design §10.4) ---
    /**
     * Sentinel written when an audit INSERT fails to preserve the tamper-evidence
     * chain's observability. Attributes carry `dropped_kind` (value of the lost
     * event kind) and `error_class` / `error_message` so operators can correlate
     * the degraded window with the originating exception. This turns a silently
     * dropped event into an attested degraded window visible to `audit:verify`
     * and operator dashboards.
     */
    case AuditWriteDegraded = 'audit.write_degraded';

    // --- Added by CW-v1 WP-1 (#1920, docs/specs/content-workflow.md) ---
    /** A workflow transition was fired (allowed) or attempted and refused (denied) via TransitionService. */
    case WorkflowTransition = 'workflow.transition';

    // --- Added by CW-v1 WP-2 task 2.5 (#1920, docs/specs/content-workflow.md) ---
    /**
     * `EntityRepository::rollback()` copied a prior revision forward as a new
     * revision. Distinct from {@see self::RevisionRevert}: a revert moves the
     * current-revision POINTER to an existing revision without creating one;
     * a rollback creates a brand-new revision from the target's values.
     */
    case RevisionRollback = 'revision.rollback';
}
