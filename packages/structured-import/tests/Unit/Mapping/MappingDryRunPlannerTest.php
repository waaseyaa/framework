<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\StructuredImport\Mapping\MappingConflictCode;
use Waaseyaa\StructuredImport\Mapping\MappingDecision;
use Waaseyaa\StructuredImport\Mapping\MappingDefinition;
use Waaseyaa\StructuredImport\Mapping\MappingDryRunPlanner;
use Waaseyaa\StructuredImport\Mapping\MappingPlan;
use Waaseyaa\StructuredImport\Mapping\MappingPlanEntry;
use Waaseyaa\StructuredImport\Mapping\MappingSourceRecord;
use Waaseyaa\StructuredImport\Mapping\MappingTargetRecord;
use Waaseyaa\StructuredImport\Mapping\ProtectedRecordValues;

#[CoversClass(MappingDefinition::class)]
#[CoversClass(MappingDryRunPlanner::class)]
#[CoversClass(MappingPlan::class)]
#[CoversClass(MappingPlanEntry::class)]
#[CoversClass(MappingSourceRecord::class)]
#[CoversClass(MappingTargetRecord::class)]
#[CoversClass(ProtectedRecordValues::class)]
final class MappingDryRunPlannerTest extends TestCase
{
    private const string CHECKSUM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function plans_create_update_unchanged_and_unique_conflict_without_values(): void
    {
        $definition = new MappingDefinition(
            id: 'staff-directory-v1',
            sourceToTarget: ['source_email' => 'email', 'source_name' => 'name'],
            ignoredSourceKeys: ['layout_note'],
            uniqueTargetFields: ['email'],
        );
        $sources = [
            new MappingSourceRecord('source-a', ['source_name' => 'Stable Alpha', 'source_email' => 'alpha@example.invalid']),
            new MappingSourceRecord('source-b', ['source_name' => 'Changed Bravo', 'source_email' => 'bravo@example.invalid']),
            new MappingSourceRecord('source-c', ['source_name' => 'Create Charlie', 'source_email' => 'charlie@example.invalid', 'layout_note' => 'ignored']),
            new MappingSourceRecord('source-d', ['source_name' => 'Conflict Delta', 'source_email' => 'occupied@example.invalid']),
        ];
        $targets = [
            new MappingTargetRecord('target-a', 'source-a', ['name' => 'Stable Alpha', 'email' => 'alpha@example.invalid']),
            new MappingTargetRecord('target-b', 'source-b', ['name' => 'Old Bravo', 'email' => 'bravo@example.invalid']),
            new MappingTargetRecord('target-z', 'source-z', ['name' => 'Existing Zulu', 'email' => 'occupied@example.invalid']),
        ];

        $plan = new MappingDryRunPlanner()->plan(self::CHECKSUM, $definition, $sources, $targets);

        self::assertSame(['create' => 1, 'update' => 1, 'unchanged' => 1, 'conflict' => 1], $plan->counts);
        $byIdentity = [];
        foreach ($plan->entries as $entry) {
            $byIdentity[$entry->sourceIdentityHash] = $entry;
        }
        self::assertSame(MappingDecision::Unchanged, $byIdentity[self::identityHash('source-a')]->decision);
        self::assertSame(MappingDecision::Update, $byIdentity[self::identityHash('source-b')]->decision);
        self::assertSame(['name'], $byIdentity[self::identityHash('source-b')]->changedFields);
        self::assertSame(MappingDecision::Create, $byIdentity[self::identityHash('source-c')]->decision);
        self::assertSame(MappingDecision::Conflict, $byIdentity[self::identityHash('source-d')]->decision);
        self::assertSame([MappingConflictCode::UniqueFieldConflict], $byIdentity[self::identityHash('source-d')]->conflictCodes);

        $json = json_encode($plan, JSON_THROW_ON_ERROR);
        foreach (['source-a', 'target-a', 'Stable Alpha', 'Changed Bravo', 'alpha@example.invalid', 'occupied@example.invalid'] as $protected) {
            self::assertStringNotContainsString($protected, $json);
        }
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan->digest);
    }

    #[Test]
    public function equivalent_reordered_inputs_have_the_same_plan_digest(): void
    {
        $definitionA = new MappingDefinition('people-v1', ['b' => 'field_b', 'a' => 'field_a'], ['ignored'], ['field_b']);
        $definitionB = new MappingDefinition('people-v1', ['a' => 'field_a', 'b' => 'field_b'], ['ignored'], ['field_b']);
        $sourceA = new MappingSourceRecord('identity-a', ['b' => 2, 'a' => 'one', 'ignored' => 'x']);
        $sourceB = new MappingSourceRecord('identity-b', ['a' => 'two', 'b' => 3]);
        $targetA = new MappingTargetRecord('record-a', 'identity-a', ['field_b' => 2, 'field_a' => 'one']);
        $planner = new MappingDryRunPlanner();

        $first = $planner->plan(self::CHECKSUM, $definitionA, [$sourceB, $sourceA], [$targetA]);
        $second = $planner->plan(self::CHECKSUM, $definitionB, [$sourceA, $sourceB], [$targetA]);

        self::assertSame($first->digest, $second->digest);
        self::assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function checksum_mapping_or_value_changes_the_digest(): void
    {
        $definition = new MappingDefinition('people-v1', ['name' => 'name']);
        $planner = new MappingDryRunPlanner();
        $base = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['name' => 'Alpha'])], []);
        $changedChecksum = $planner->plan(str_repeat('b', 64), $definition, [new MappingSourceRecord('one', ['name' => 'Alpha'])], []);
        $changedValue = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['name' => 'Bravo'])], []);
        $changedMapping = $planner->plan(self::CHECKSUM, new MappingDefinition('people-v1', ['name' => 'title']), [new MappingSourceRecord('one', ['name' => 'Alpha'])], []);

        self::assertNotSame($base->digest, $changedChecksum->digest);
        self::assertNotSame($base->digest, $changedValue->digest);
        self::assertNotSame($base->digest, $changedMapping->digest);
    }

    #[Test]
    public function scalar_type_and_runtime_float_precision_cannot_change_digest_semantics(): void
    {
        $definition = new MappingDefinition('numbers-v1', ['number' => 'number']);
        $planner = new MappingDryRunPlanner();
        $integer = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['number' => 1])], []);
        $float = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['number' => 1.0])], []);

        self::assertNotSame($integer->digest, $float->digest);

        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '-1');
            $first = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['number' => 1.2345678901234567])], []);
            ini_set('serialize_precision', '17');
            $second = $planner->plan(self::CHECKSUM, $definition, [new MappingSourceRecord('one', ['number' => 1.2345678901234567])], []);
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
        self::assertSame($first->digest, $second->digest);
    }

    #[Test]
    public function equivalent_duplicate_target_snapshots_have_a_total_digest_order(): void
    {
        $definition = new MappingDefinition('people-v1', ['name' => 'name']);
        $source = new MappingSourceRecord('duplicate', ['name' => 'Current']);
        $firstTarget = new MappingTargetRecord('same-record', 'duplicate', ['name' => 'First']);
        $secondTarget = new MappingTargetRecord('same-record', 'duplicate', ['name' => 'Second']);
        $planner = new MappingDryRunPlanner();

        $first = $planner->plan(self::CHECKSUM, $definition, [$source], [$firstTarget, $secondTarget]);
        $second = $planner->plan(self::CHECKSUM, $definition, [$source], [$secondTarget, $firstTarget]);

        self::assertSame($first->digest, $second->digest);
    }

    #[Test]
    public function digit_only_spreadsheet_headers_are_normalized_as_field_names(): void
    {
        $definition = new MappingDefinition('yearly-v1', ['2024' => 'population_2024']);
        $plan = new MappingDryRunPlanner()->plan(
            self::CHECKSUM,
            $definition,
            [new MappingSourceRecord('one', ['2024' => 42])],
            [],
        );

        self::assertSame(MappingDecision::Create, $plan->entries[0]->decision);
    }

    #[Test]
    public function digit_only_unmapped_headers_remain_strings_in_plan_json(): void
    {
        $plan = new MappingDryRunPlanner()->plan(
            self::CHECKSUM,
            new MappingDefinition('yearly-v1', ['name' => 'name']),
            [new MappingSourceRecord('one', ['name' => 'Alpha', '2024' => 42])],
            [],
        );

        self::assertSame(['2024'], $plan->entries[0]->conflictFields);
        self::assertStringContainsString('"conflict_fields":["2024"]', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function duplicate_source_and_target_identities_are_conflicts_not_writes(): void
    {
        $definition = new MappingDefinition('people-v1', ['name' => 'name']);
        $sources = [
            new MappingSourceRecord('duplicate', ['name' => 'Alpha']),
            new MappingSourceRecord('duplicate', ['name' => 'Bravo']),
            new MappingSourceRecord('target-duplicate', ['name' => 'Charlie']),
        ];
        $targets = [
            new MappingTargetRecord('one', 'target-duplicate', ['name' => 'Old One']),
            new MappingTargetRecord('two', 'target-duplicate', ['name' => 'Old Two']),
        ];

        $plan = new MappingDryRunPlanner()->plan(self::CHECKSUM, $definition, $sources, $targets);

        self::assertSame(['create' => 0, 'update' => 0, 'unchanged' => 0, 'conflict' => 3], $plan->counts);
        self::assertSame(MappingConflictCode::DuplicateSourceIdentity, $plan->entries[0]->conflictCodes[0]);
        self::assertSame(MappingConflictCode::DuplicateSourceIdentity, $plan->entries[1]->conflictCodes[0]);
        self::assertSame(MappingConflictCode::DuplicateTargetIdentity, $plan->entries[2]->conflictCodes[0]);
    }

    #[Test]
    public function unmapped_source_fields_require_an_explicit_ignore_declaration(): void
    {
        $plan = new MappingDryRunPlanner()->plan(
            self::CHECKSUM,
            new MappingDefinition('people-v1', ['name' => 'name']),
            [new MappingSourceRecord('one', ['name' => 'Alpha', 'layout' => 'Heading'])],
            [],
        );

        self::assertSame(MappingDecision::Conflict, $plan->entries[0]->decision);
        self::assertSame([MappingConflictCode::UnmappedSourceField], $plan->entries[0]->conflictCodes);
        self::assertSame(['layout'], $plan->entries[0]->conflictFields);
    }

    #[Test]
    public function protected_records_refuse_serialization_and_redact_debug_output(): void
    {
        $record = new MappingSourceRecord('private-identity', ['name' => 'Private Value']);
        self::assertStringNotContainsString('Private Value', print_r($record, true));
        self::assertStringNotContainsString('private-identity', print_r($record, true));

        $this->expectException(\LogicException::class);
        serialize($record);
    }

    private static function identityHash(string $identity): string
    {
        return hash('sha256', "waaseyaa.structured-import.source-identity.v1\0".$identity);
    }
}
