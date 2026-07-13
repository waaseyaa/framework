<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Waaseyaa\AI\Tools\Entity\EntityKeyGuard;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Validation\EntityValidationException;

/**
 * WP03 / T010: the identity-key refusal set per
 * contracts/tool-refusal.md clause 1 (mission
 * live-entity-validation-key-protection-01KTWQT3).
 */
#[CoversClass(EntityKeyGuard::class)]
final class EntityKeyGuardTest extends TestCase
{
    /** @param array<string, string> $keys */
    private function definition(array $keys): EntityType
    {
        // Note: the guard only reads getKeys(); the EntityType `translatable`
        // flag is irrelevant to the refusal set (the literal floor protects
        // langcode columns regardless), so non-translatable fixtures suffice.
        return new EntityType(
            id: 'guard_test',
            label: 'Guard Test',
            class: ToolTestEntity::class,
            keys: $keys,
        );
    }

    #[Test]
    public function every_registered_refusable_kind_is_refused(): void
    {
        $definition = $this->definition([
            'id' => 'id',
            'uuid' => 'uuid',
            'revision' => 'vid',
            'langcode' => 'langcode',
            'default_langcode' => 'default_langcode',
            'label' => 'title',
        ]);

        $refused = EntityKeyGuard::refusedKeys($definition, [
            'id' => '9',
            'uuid' => 'abc',
            'vid' => 4,
            'langcode' => 'xx',
            'default_langcode' => true,
            'title' => 'fine',
        ]);

        self::assertSame(['default_langcode', 'id', 'langcode', 'uuid', 'vid'], $refused);
    }

    #[Test]
    public function renamed_id_column_is_refused_under_its_real_name(): void
    {
        $definition = $this->definition(['id' => 'nid', 'label' => 'title']);

        $refused = EntityKeyGuard::refusedKeys($definition, ['nid' => '42', 'title' => 'ok']);

        self::assertSame(['nid'], $refused);
    }

    #[Test]
    public function literal_floor_is_refused_even_when_the_kinds_are_unregistered(): void
    {
        // Only an id kind is registered: uuid/langcode/default_langcode kinds
        // are absent, but the literal column names are still refused (D4).
        $definition = $this->definition(['id' => 'id']);

        $refused = EntityKeyGuard::refusedKeys($definition, [
            'uuid' => 'abc',
            'langcode' => 'xx',
            'default_langcode' => false,
        ]);

        self::assertSame(['default_langcode', 'langcode', 'uuid'], $refused);
    }

    /**
     * CW-v1 option-1 PR-4 (findings #1/#2): `published_revision_id` carries
     * NO entity-key kind on any shipped entity type (only `revision` =>
     * `revision_id` is registered) — only the literal floor closes it.
     */
    #[Test]
    public function revision_pointer_columns_are_refused_even_when_unregistered(): void
    {
        $definition = $this->definition(['id' => 'id', 'revision' => 'revision_id']);

        $refused = EntityKeyGuard::refusedKeys($definition, [
            'revision_id' => 2,
            'published_revision_id' => 5,
        ]);

        self::assertSame(['published_revision_id', 'revision_id'], $refused);
    }

    #[Test]
    public function label_and_bundle_columns_pass(): void
    {
        $definition = $this->definition([
            'id' => 'id',
            'label' => 'title',
            'bundle' => 'type',
        ]);

        $refused = EntityKeyGuard::refusedKeys($definition, ['title' => 'New', 'type' => 'article']);

        self::assertSame([], $refused);
    }

    #[Test]
    public function empty_values_yield_an_empty_list(): void
    {
        $definition = $this->definition(['id' => 'id', 'uuid' => 'uuid']);

        self::assertSame([], EntityKeyGuard::refusedKeys($definition, []));
    }

    #[Test]
    public function non_string_values_keys_are_ignored(): void
    {
        $definition = $this->definition(['id' => 'id']);

        self::assertSame([], EntityKeyGuard::refusedKeys($definition, [0 => 'id', 7 => 'uuid']));
    }

    #[Test]
    public function output_is_sorted_alphabetically_regardless_of_payload_order(): void
    {
        $definition = $this->definition(['id' => 'id', 'uuid' => 'uuid', 'revision' => 'vid']);

        $refused = EntityKeyGuard::refusedKeys($definition, [
            'vid' => 1,
            'uuid' => 'abc',
            'langcode' => 'xx',
            'id' => '5',
        ]);

        self::assertSame(['id', 'langcode', 'uuid', 'vid'], $refused);
    }

    #[Test]
    public function refusal_error_matches_the_contract_shape(): void
    {
        $result = EntityKeyGuard::refusalError('entity.update', ['langcode', 'uuid']);

        self::assertTrue($result->isError);
        self::assertSame(
            'entity.update: refused identity keys: langcode, uuid — identity fields cannot be written through this tool',
            $result->content[0]['text'] ?? null,
        );
        self::assertSame(
            ['error' => 'identity_keys_refused', 'refused_keys' => ['langcode', 'uuid']],
            $result->content[1]['data'] ?? null,
        );
    }

    #[Test]
    public function validation_error_sorts_violations_by_field_with_insertion_order_tiebreak(): void
    {
        // Deliberately out of alphabetical order, with a duplicate field to
        // exercise the stable tiebreak (NFR-003).
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Too long.', null, [], null, 'title', 'zzz'),
            new ConstraintViolation('Out of range.', null, [], null, 'score', 200),
            new ConstraintViolation('Not a string.', null, [], null, 'title', 42),
        ]);
        $exception = new EntityValidationException($violations);

        $result = EntityKeyGuard::validationError('entity.create', $exception);

        self::assertTrue($result->isError);
        self::assertSame(
            'entity.create: validation failed: score: Out of range.; title: Too long.; title: Not a string.',
            $result->content[0]['text'] ?? null,
        );
        self::assertSame(
            [
                'error' => 'validation_failed',
                'violations' => [
                    ['field' => 'score', 'message' => 'Out of range.', 'invalid_value_type' => 'int'],
                    ['field' => 'title', 'message' => 'Too long.', 'invalid_value_type' => 'string'],
                    ['field' => 'title', 'message' => 'Not a string.', 'invalid_value_type' => 'int'],
                ],
            ],
            $result->content[1]['data'] ?? null,
        );
    }
}
