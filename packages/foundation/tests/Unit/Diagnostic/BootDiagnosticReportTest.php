<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;

#[CoversClass(BootDiagnosticReport::class)]
final class BootDiagnosticReportTest extends TestCase
{
    private function makeType(string $id): EntityType
    {
        return new EntityType(id: $id, label: ucfirst($id), class: \stdClass::class);
    }

    #[Test]
    public function enabledTypeIdsExcludesDisabled(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: [
                'note'    => $this->makeType('note'),
                'article' => $this->makeType('article'),
            ],
            disabledTypeIds: ['article'],
            schemaCompatibility: [],
        );

        $this->assertSame(['note'], $report->enabledTypeIds());
    }

    #[Test]
    public function hasEnabledTypesReturnsTrueWhenAtLeastOneEnabled(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        $this->assertTrue($report->hasEnabledTypes());
    }

    #[Test]
    public function hasEnabledTypesReturnsFalseWhenAllDisabled(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: ['note'],
            schemaCompatibility: [],
        );

        $this->assertFalse($report->hasEnabledTypes());
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: [],
            schemaCompatibility: ['note' => 'liberal'],
        );

        $arr = $report->toArray();

        $this->assertArrayHasKey('registered', $arr);
        $this->assertArrayHasKey('disabled', $arr);
        $this->assertArrayHasKey('enabled', $arr);
        $this->assertArrayHasKey('schema_compatibility', $arr);
        $this->assertArrayHasKey('healthy', $arr);
    }

    #[Test]
    public function toArrayHealthyIsTrueWhenEnabledTypesExist(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        $this->assertTrue($report->toArray()['healthy']);
    }

    #[Test]
    public function toArrayHealthyIsFalseWhenNoEnabledTypes(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: ['note'],
            schemaCompatibility: [],
        );

        $this->assertFalse($report->toArray()['healthy']);
    }

    #[Test]
    public function schemaCompatibilityIsIncludedInReport(): void
    {
        $report = new BootDiagnosticReport(
            registeredTypes: ['note' => $this->makeType('note')],
            disabledTypeIds: [],
            schemaCompatibility: ['note' => 'liberal'],
        );

        $this->assertSame(['note' => 'liberal'], $report->toArray()['schema_compatibility']);
    }
}
