<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Enum\AuditEventKind;

/**
 * Verifies the additive amendment of AuditEventKind with media-version kinds.
 *
 * Added by `versioned-blob-media-abstraction-01KSEFTJ` per the
 * §Out-of-band downstream-amendment principle in the OCAP audit spec.
 */
#[CoversClass(AuditEventKind::class)]
final class AuditEventKindAmendmentTest extends TestCase
{
    #[Test]
    public function media_version_created_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::MediaVersionCreated,
            AuditEventKind::tryFrom('media.version.created'),
        );
    }

    #[Test]
    public function media_version_read_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::MediaVersionRead,
            AuditEventKind::tryFrom('media.version.read'),
        );
    }

    #[Test]
    public function media_version_dedup_hit_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::MediaVersionDedupHit,
            AuditEventKind::tryFrom('media.version.dedup_hit'),
        );
    }

    #[Test]
    public function total_case_count_is_twenty_eight(): void
    {
        // Originally 14 cases (OCAP substrate). Extended additively to 17
        // by versioned-blob-media-abstraction-01KSEFTJ (WP02), then to 19
        // by revision-audit-provenance-01KTWY5V (revision.publish /
        // revision.revert, FR-006), then to 20 by WP3 audit tamper-evidence
        // verify (audit.verify), then to 21 by WP4 fail-open marker+metric
        // (#1792, audit.write_degraded), then to 22 by CW-v1 WP-1
        // (#1920, workflow.transition), then to 23 by CW-v1 WP-2 task 2.5
        // (#1920, revision.rollback), then to 28 by content-publishing v1
        // (#2136, content.draft_saved / content.published /
        // content.unpublished / content.rolled_back / content.preview_issued).
        self::assertCount(28, AuditEventKind::cases());
    }

    #[Test]
    public function content_publishing_kinds_resolve_from_strings(): void
    {
        self::assertSame(AuditEventKind::ContentDraftSaved, AuditEventKind::tryFrom('content.draft_saved'));
        self::assertSame(AuditEventKind::ContentPublished, AuditEventKind::tryFrom('content.published'));
        self::assertSame(AuditEventKind::ContentUnpublished, AuditEventKind::tryFrom('content.unpublished'));
        self::assertSame(AuditEventKind::ContentRolledBack, AuditEventKind::tryFrom('content.rolled_back'));
        self::assertSame(AuditEventKind::ContentPreviewIssued, AuditEventKind::tryFrom('content.preview_issued'));
    }

    #[Test]
    public function workflow_transition_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::WorkflowTransition,
            AuditEventKind::tryFrom('workflow.transition'),
        );
    }

    #[Test]
    public function audit_write_degraded_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::AuditWriteDegraded,
            AuditEventKind::tryFrom('audit.write_degraded'),
        );
    }

    #[Test]
    public function revision_publish_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::RevisionPublish,
            AuditEventKind::tryFrom('revision.publish'),
        );
    }

    #[Test]
    public function revision_revert_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::RevisionRevert,
            AuditEventKind::tryFrom('revision.revert'),
        );
    }

    #[Test]
    public function revision_rollback_resolves_from_string(): void
    {
        self::assertSame(
            AuditEventKind::RevisionRollback,
            AuditEventKind::tryFrom('revision.rollback'),
        );
    }

    #[Test]
    public function original_fourteen_cases_remain_intact(): void
    {
        $originalCases = [
            'entity.read', 'entity.write', 'entity.delete', 'entity.export',
            'access.denied', 'classification.change',
            'retention.purge', 'retention.redact', 'retention.hold',
            'agent.tool_execute', 'mcp.dispatch', 'broadcast.publish',
            'api.request', 'audit.retention_pruned',
        ];

        foreach ($originalCases as $backing) {
            self::assertNotNull(
                AuditEventKind::tryFrom($backing),
                sprintf('Expected case with backing value "%s" to exist.', $backing),
            );
        }
    }
}
