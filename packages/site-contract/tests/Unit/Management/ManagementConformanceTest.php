<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Management;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Capability\CapabilityState;
use Waaseyaa\SiteContract\Management\ManagementConformance;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\Management\ManagementVerificationResult;
use Waaseyaa\SiteContract\Tests\Fixtures\Management\ManagementFixture as F;

final class ManagementConformanceTest extends TestCase
{
    private function codes($inventory = null, ?array $document = null, CapabilityState $state = CapabilityState::Active): array
    {
        return array_column(new ManagementConformance()->inspect(F::manifest($document), F::site($state), str_repeat('c', 64), $inventory), 'id');
    }

    public function testAbsentInventoryCannotProveRegistration(): void
    {
        self::assertSame(['SITE035_MANAGEMENT_INVENTORY_UNVERIFIED'], $this->codes());
    }

    public function testMatchingCurrentInventoryAndExecutedResultsConform(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        self::assertSame([], $this->codes(F::inventory([$op])));
    }

    public function testSourceDriftAndInactiveCapabilitiesRefuse(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        self::assertSame(['SITE036_MANAGEMENT_SOURCE_DRIFT'], $this->codes(F::inventory([$op], null, str_repeat('d', 64))));
        self::assertSame(['SITE036_MANAGEMENT_SOURCE_DRIFT'], $this->codes(F::inventory([$op], null, null, str_repeat('d', 64))));
        self::assertSame(['SITE034_MANAGEMENT_CAPABILITY_INACTIVE'], $this->codes(F::inventory([$op]), null, CapabilityState::Planned));
    }

    public function testMissingAndUnexpectedRegistrationsRefuse(): void
    {
        self::assertSame(['SITE039_MANAGEMENT_OPERATION_MISSING'], $this->codes(F::inventory([])));
        $op = new ManagementManifestParser()->operation(F::operation('forms.delete'));
        self::assertSame(['SITE038_UNDECLARED_MANAGEMENT_OPERATION', 'SITE039_MANAGEMENT_OPERATION_MISSING'], $this->codes(F::inventory([$op])));
    }

    #[DataProvider('driftFields')]
    public function testRuntimeContractDriftIsVisible(string $field, mixed $value): void
    {
        $row = F::operation(); $row[$field] = $value;
        $op = new ManagementManifestParser()->operation($row);
        self::assertSame(['SITE040_MANAGEMENT_CONTRACT_DRIFT'], $this->codes(F::inventory([$op])));
    }

    public static function driftFields(): iterable
    {
        yield 'scopes' => ['required_scopes', ['forms:publish']];
        yield 'preview' => ['dry_run', 'supported'];
        yield 'retry' => ['idempotency', 'none'];
        yield 'concurrency' => ['concurrency', 'etag'];
        yield 'effect' => ['effects', ['publish']];
        yield 'tenant' => ['tenant_scope', 'system'];
        yield 'audit' => ['audit', 'none'];
        yield 'approval' => ['approval', 'required'];
        yield 'binding' => ['binding', ['transport' => 'cli', 'name' => 'create-form']];
    }

    public function testFailedMissingAndStaleVerificationNeverPass(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        self::assertCount(2, $this->codes(F::inventory([$op], [])));
        foreach ([false, true] as $passed) {
            $result = new ManagementVerificationResult($op->id, 'contact-draft-retry',
                $passed ? str_repeat('e', 64) : $op->digest(), $passed, 'synthetic-check', str_repeat('c', 64), str_repeat('b', 64));
            self::assertSame(['SITE041_MANAGEMENT_VERIFICATION_UNPROVEN', 'SITE041_MANAGEMENT_VERIFICATION_UNPROVEN'],
                $this->codes(F::inventory([$op], [$result])));
        }
    }

    public function testDuplicateInventoryAndResultsFailClosed(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        self::assertSame(['SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE'], $this->codes(F::inventory([$op, $op])));
        $results = F::results([$op]); $results[] = $results[0];
        self::assertSame(['SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE'], $this->codes(F::inventory([$op], $results)));
    }

    public function testInvalidRuntimeRowsFailClosed(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        self::assertSame(['SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE'], $this->codes(F::inventory([new \stdClass()], [])));
        self::assertSame(['SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE'], $this->codes(F::inventory([$op], [new \stdClass()])));
    }

    public function testOldResultsCannotSurviveSourceOrSiteRebinding(): void
    {
        $op = new ManagementManifestParser()->operation(F::operation());
        foreach ([[str_repeat('d', 64), str_repeat('b', 64)], [str_repeat('c', 64), str_repeat('d', 64)]] as [$source, $site]) {
            self::assertCount(2, $this->codes(F::inventory([$op], F::results([$op], $source, $site))));
        }
    }

    public function testActualNorthCloudBindingsAndScopes(): void
    {
        foreach ([
            ['collection.seed', 'collection:seed', 'POST /v1/collection/seeds', 'create'],
            ['collection.status', 'collection:status', 'GET /v1/collection/status', 'read'],
            ['collection.observations', 'collection:observations:read', 'GET /v1/collection/observations', 'read'],
        ] as [$id, $scope, $binding, $effect]) {
            // Endpoint identities from NorthCloud 1f83579 checkpoint. Envelopes and
            // evidence are synthetic, not product execution.
            $row = F::operation($id);
            $row['binding'] = ['transport' => 'api', 'name' => $binding];
            $row['required_scopes'] = [$scope];
            $row['effects'] = [$effect];
            $row['idempotency'] = $id === 'collection.seed' ? 'natural' : 'none';
            $row['verification'] = ['tenant-denial'];
            $op = new ManagementManifestParser()->operation($row);
            self::assertSame([], $this->codes(F::inventory([$op]), F::document($row)));
            self::assertSame(['SITE039_MANAGEMENT_OPERATION_MISSING'], $this->codes(F::inventory([]), F::document($row)));
            $row['required_scopes'] = ['wrong:scope'];
            $drift = new ManagementManifestParser()->operation($row);
            self::assertSame(['SITE040_MANAGEMENT_CONTRACT_DRIFT'], $this->codes(F::inventory([$drift]), F::document($op->toArray())));
        }
    }
}
