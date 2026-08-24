<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\Config\Sync\ConfigValidateEntry;
use Waaseyaa\Config\Sync\ConfigValidateResult;
use Waaseyaa\Config\Sync\FieldViolation;

#[CoversClass(ConfigSyncValidator::class)]
#[CoversClass(ConfigValidateResult::class)]
#[CoversClass(ConfigValidateEntry::class)]
#[CoversClass(FieldViolation::class)]
final class ConfigSyncValidatorTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_validator_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function empty_repository_returns_valid_result(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
        self::assertSame([], $result->entries);
        self::assertSame(0, $result->totalViolations());
    }

    #[Test]
    public function entries_are_emitted_in_alphabetical_ref_order(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'zeta', ['label' => 'Z']));
        $repo->put($this->makeFile('role', 'admin', ['label' => 'A']));
        $repo->put($this->makeFile('role', 'member', ['label' => 'M']));
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        $refs = array_map(static fn(ConfigValidateEntry $e) => $e->ref, $result->entries);
        self::assertSame(['role.admin', 'role.member', 'role.zeta'], $refs);
    }

    #[Test]
    public function default_fallback_flags_empty_fields_map_per_required_check_rule(): void
    {
        // WP06 spec §10.1 fallback: when FieldDefinition::validators() is not
        // shipped, a generic "is required" check fires on empty payloads.
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin', []));
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        $entry = $result->entries[0];
        self::assertSame('role.admin', $entry->ref);
        self::assertCount(1, $entry->violations);
        self::assertSame('fields', $entry->violations[0]->field);
        self::assertSame('fields.empty', $entry->violations[0]->code);
    }

    #[Test]
    public function default_fallback_accepts_files_with_at_least_one_field(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin', ['label' => 'Admin']));
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function app_supplied_hook_replaces_fallback_and_surfaces_per_field_violations(): void
    {
        // Models the post-ADR-013 wiring: application supplies a hook that
        // walks FieldDefinition::validators() and yields structured violations.
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('taxonomy_vocabulary', 'community_categories', [
            'description' => '',
            'weight' => -5,
        ]));
        $hook = static function (ConfigSyncFile $file): array {
            $violations = [];
            $description = $file->fields['description'] ?? null;
            if (\is_string($description) && $description === '') {
                $violations[] = new FieldViolation(
                    field: 'description',
                    message: 'must be at least 1 character',
                    code: 'string.min_length',
                );
            }
            $weight = $file->fields['weight'] ?? null;
            if (\is_int($weight) && $weight < 0) {
                $violations[] = new FieldViolation(
                    field: 'weight',
                    message: 'must be non-negative',
                    code: 'integer.non_negative',
                );
            }

            return $violations;
        };
        $validator = new ConfigSyncValidator($repo, $hook);

        $result = $validator->validate();

        self::assertFalse($result->isValid());
        self::assertSame(2, $result->totalViolations());
        $entry = $result->entries[0];
        self::assertSame('taxonomy_vocabulary.community_categories', $entry->ref);
        self::assertSame(['description', 'weight'], array_map(
            static fn(FieldViolation $v) => $v->field,
            $entry->violations,
        ));
    }

    #[Test]
    public function failing_entries_returns_only_invalid_entries(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin', ['label' => 'Admin']));
        $repo->put($this->makeFile('role', 'broken', []));
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        $failing = $result->failingEntries();
        self::assertCount(1, $failing);
        self::assertSame('role.broken', $failing[0]->ref);
    }

    #[Test]
    public function validate_file_runs_hook_against_in_memory_instance(): void
    {
        $repo = new ConfigSyncRepository($this->tempDir);
        $hook = static fn(ConfigSyncFile $file): array => [
            new FieldViolation(field: 'label', message: 'forced failure', code: 'test.forced'),
        ];
        $validator = new ConfigSyncValidator($repo, $hook);
        $file = $this->makeFile('role', 'admin', ['label' => 'Admin']);

        $violations = $validator->validateFile($file);

        self::assertCount(1, $violations);
        self::assertSame('label', $violations[0]->field);
        self::assertSame('test.forced', $violations[0]->code);
    }

    #[Test]
    public function valid_entries_render_with_zero_violations(): void
    {
        // FR-039 wording — entries with no violations are reported as
        // "OK" by the CLI; at the value-object layer that maps to
        // `isValid() === true` with `violations === []`.
        $repo = new ConfigSyncRepository($this->tempDir);
        $repo->put($this->makeFile('role', 'admin', ['label' => 'Admin']));
        $validator = new ConfigSyncValidator($repo);

        $result = $validator->validate();

        self::assertCount(1, $result->entries);
        self::assertTrue($result->entries[0]->isValid());
        self::assertSame([], $result->entries[0]->violations);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeFile(string $entityType, string $entityId, array $fields): ConfigSyncFile
    {
        ksort($fields, \SORT_STRING);

        return ConfigSyncFile::writable(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
    }

}
