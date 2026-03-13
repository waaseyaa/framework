<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ValidationReport;

#[CoversClass(ValidationReport::class)]
final class ValidationReportTest extends TestCase
{
    #[Test]
    public function to_array_returns_correct_structure(): void
    {
        $report = new ValidationReport(
            sessionId: 'test-session-123',
            driftScore: 85,
            semanticAlignment: 51.0,
            structuralScore: 20.0,
            contradictionScore: 15.0,
            issues: ['Missing file: docs/spec.md'],
            recommendation: 'Context alignment is strong.',
        );

        $array = $report->toArray();

        $this->assertSame('test-session-123', $array['session_id']);
        $this->assertSame(85, $array['drift_score']);
        $this->assertSame(51.0, $array['semantic_alignment']);
        $this->assertSame(20.0, $array['structural_score']);
        $this->assertSame(15.0, $array['contradiction_score']);
        $this->assertSame(['Missing file: docs/spec.md'], $array['issues']);
        $this->assertSame('Context alignment is strong.', $array['recommendation']);
        $this->assertArrayHasKey('validated_at', $array);
    }

    #[Test]
    public function validated_at_is_set_on_construction(): void
    {
        $before = new \DateTimeImmutable();
        $report = new ValidationReport(
            sessionId: 'sess',
            driftScore: 50,
            semanticAlignment: 30.0,
            structuralScore: 10.0,
            contradictionScore: 10.0,
            issues: [],
            recommendation: 'OK',
        );
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $report->validatedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $report->validatedAt->getTimestamp());
    }

    #[Test]
    public function to_json_returns_valid_json(): void
    {
        $report = new ValidationReport(
            sessionId: 'json-test',
            driftScore: 60,
            semanticAlignment: 36.0,
            structuralScore: 12.0,
            contradictionScore: 12.0,
            issues: [],
            recommendation: 'Medium drift.',
        );

        $json = $report->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('json-test', $decoded['session_id']);
        $this->assertSame(60, $decoded['drift_score']);
    }

    #[Test]
    public function to_json_validated_at_is_atom_format(): void
    {
        $report = new ValidationReport(
            sessionId: 'fmt-test',
            driftScore: 75,
            semanticAlignment: 45.0,
            structuralScore: 20.0,
            contradictionScore: 10.0,
            issues: [],
            recommendation: 'Low drift.',
        );

        $array = $report->toArray();
        // Atom format: 2026-03-12T10:00:00+00:00
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $array['validated_at']);
    }
}
