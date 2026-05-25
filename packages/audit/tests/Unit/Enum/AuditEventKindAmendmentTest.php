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
    public function total_case_count_is_seventeen(): void
    {
        // Originally 14 cases (OCAP substrate). Extended additively to 17
        // by versioned-blob-media-abstraction-01KSEFTJ (WP02).
        self::assertCount(17, AuditEventKind::cases());
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
