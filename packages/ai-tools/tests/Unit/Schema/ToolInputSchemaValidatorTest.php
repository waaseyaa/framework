<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;

/**
 * Unit matrix for the first-party JSON Schema (draft 2020-12 subset)
 * validator that guards MCP `tools/call` arguments (#2145).
 *
 * Values arrive exactly as `json_decode($body, true)` produces them:
 * objects are associative arrays (`{}` and `[]` both decode to `[]`),
 * so the object/array distinction leans on `array_is_list()` with the
 * empty array accepted for both.
 */
#[CoversClass(ToolInputSchemaValidator::class)]
final class ToolInputSchemaValidatorTest extends TestCase
{
    /** @return list<string> The violated field paths. */
    private static function fields(array $schema, mixed $value): array
    {
        return array_map(
            static fn(array $v): string => $v['field'],
            ToolInputSchemaValidator::validate($schema, $value),
        );
    }

    #[Test]
    public function valid_input_produces_no_violations(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1],
                'target_revision_id' => ['type' => 'integer', 'minimum' => 1],
                'note' => ['type' => 'string', 'maxLength' => 500],
            ],
            'required' => ['id', 'target_revision_id'],
            'additionalProperties' => false,
        ];

        self::assertSame([], ToolInputSchemaValidator::validate($schema, [
            'id' => '42',
            'target_revision_id' => 7,
            'note' => 'restore',
        ]));
    }

    #[Test]
    public function a_missing_required_field_is_reported_by_name(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'target_revision_id' => ['type' => 'integer'],
            ],
            'required' => ['id', 'target_revision_id'],
        ];

        $violations = ToolInputSchemaValidator::validate($schema, ['id' => '42']);

        self::assertCount(1, $violations);
        self::assertSame('target_revision_id', $violations[0]['field']);
        self::assertStringContainsString('required', strtolower($violations[0]['message']));
    }

    #[Test]
    public function an_empty_arguments_object_fails_every_required_field(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'required' => ['id'],
        ];

        // `{}` and `[]` both json_decode to [] — both must fail `required`.
        self::assertSame(['id'], self::fields($schema, []));
    }

    #[Test]
    public function wrong_scalar_types_are_rejected(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'flag' => ['type' => 'boolean'],
                'ratio' => ['type' => 'number'],
            ],
        ];

        self::assertSame(['count'], self::fields($schema, ['count' => 'seven']));
        self::assertSame(['count'], self::fields($schema, ['count' => 1.5]));
        self::assertSame(['name'], self::fields($schema, ['name' => 42]));
        self::assertSame(['flag'], self::fields($schema, ['flag' => 1]));
        self::assertSame(['ratio'], self::fields($schema, ['ratio' => 'fast']));
    }

    #[Test]
    public function json_integral_floats_satisfy_integer(): void
    {
        // JSON Schema: 2.0 is a valid "integer".
        $schema = ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]];

        self::assertSame([], self::fields($schema, ['count' => 2.0]));
        self::assertSame([], self::fields($schema, ['count' => 3]));
    }

    #[Test]
    public function booleans_never_satisfy_string_or_number(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'n' => ['type' => 'number']],
        ];

        self::assertSame(['name'], self::fields($schema, ['name' => true]));
        self::assertSame(['n'], self::fields($schema, ['n' => false]));
    }

    #[Test]
    public function a_type_list_accepts_any_member(): void
    {
        $schema = ['type' => 'object', 'properties' => ['v' => ['type' => ['string', 'null']]]];

        self::assertSame([], self::fields($schema, ['v' => 'x']));
        self::assertSame([], self::fields($schema, ['v' => null]));
        self::assertSame(['v'], self::fields($schema, ['v' => 3]));
    }

    #[Test]
    public function invalid_enum_values_are_rejected(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['direction' => ['enum' => ['out', 'in', 'both']]],
        ];

        self::assertSame([], self::fields($schema, ['direction' => 'both']));
        self::assertSame(['direction'], self::fields($schema, ['direction' => 'sideways']));
    }

    #[Test]
    public function const_must_match_exactly(): void
    {
        $schema = ['type' => 'object', 'properties' => ['mode' => ['const' => 'live']]];

        self::assertSame([], self::fields($schema, ['mode' => 'live']));
        self::assertSame(['mode'], self::fields($schema, ['mode' => 'dry']));
    }

    #[Test]
    public function additional_properties_false_rejects_unknown_keys(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string']],
            'additionalProperties' => false,
        ];

        $violations = ToolInputSchemaValidator::validate($schema, ['id' => 'x', 'bogus' => 1]);

        self::assertCount(1, $violations);
        self::assertSame('bogus', $violations[0]['field']);
    }

    #[Test]
    public function additional_properties_schema_validates_extra_values(): void
    {
        // The EntityListTool `sort` shape: additionalProperties => {enum: [ASC, DESC]}.
        $schema = [
            'type' => 'object',
            'properties' => [
                'sort' => ['type' => 'object', 'additionalProperties' => ['enum' => ['ASC', 'DESC']]],
            ],
        ];

        self::assertSame([], self::fields($schema, ['sort' => ['created' => 'DESC']]));
        self::assertSame(['sort.created'], self::fields($schema, ['sort' => ['created' => 'down']]));
    }

    #[Test]
    public function nested_object_violations_carry_dotted_paths(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'values' => [
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string']],
                    'required' => ['title'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['values'],
        ];

        self::assertSame(['values.title'], self::fields($schema, ['values' => []]));
        self::assertSame(['values.title'], self::fields($schema, ['values' => ['title' => 9]]));
        self::assertSame(['values.hack'], self::fields($schema, ['values' => ['title' => 'ok', 'hack' => 1]]));
    }

    #[Test]
    public function object_type_rejects_lists_and_scalars(): void
    {
        $schema = ['type' => 'object', 'properties' => ['values' => ['type' => 'object']]];

        self::assertSame(['values'], self::fields($schema, ['values' => ['a', 'b']]));
        self::assertSame(['values'], self::fields($schema, ['values' => 'text']));
        self::assertSame([], self::fields($schema, ['values' => []]));
        self::assertSame([], self::fields($schema, ['values' => ['k' => 'v']]));
    }

    #[Test]
    public function array_type_requires_a_list_and_items_recurse(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
            ],
        ];

        self::assertSame([], self::fields($schema, ['tags' => ['a', 'b']]));
        self::assertSame(['tags'], self::fields($schema, ['tags' => ['k' => 'v']]));
        self::assertSame(['tags.1'], self::fields($schema, ['tags' => ['a', 2]]));
        self::assertSame(['tags'], self::fields($schema, ['tags' => []]));
        self::assertSame(['tags'], self::fields($schema, ['tags' => ['a', 'b', 'c', 'd']]));
    }

    #[Test]
    public function string_and_numeric_bounds_are_enforced(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'offset' => ['type' => 'integer', 'exclusiveMinimum' => -1],
            ],
        ];

        self::assertSame(['idempotency_key'], self::fields($schema, ['idempotency_key' => 'short']));
        self::assertSame(['idempotency_key'], self::fields($schema, ['idempotency_key' => str_repeat('k', 129)]));
        self::assertSame(['limit'], self::fields($schema, ['limit' => 0]));
        self::assertSame(['limit'], self::fields($schema, ['limit' => 101]));
        self::assertSame(['offset'], self::fields($schema, ['offset' => -1]));
        self::assertSame([], self::fields($schema, [
            'idempotency_key' => 'long-enough-key',
            'limit' => 50,
            'offset' => 0,
        ]));
    }

    #[Test]
    public function pattern_is_enforced_when_declared(): void
    {
        $schema = ['type' => 'object', 'properties' => ['slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$']]];

        self::assertSame([], self::fields($schema, ['slug' => 'my-post-1']));
        self::assertSame(['slug'], self::fields($schema, ['slug' => 'My Post!']));
    }

    #[Test]
    public function an_empty_schema_accepts_anything(): void
    {
        self::assertSame([], ToolInputSchemaValidator::validate([], ['whatever' => [1, 2, 3]]));
    }

    #[Test]
    public function unknown_keywords_are_ignored(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer', 'default' => 5, 'x-hint' => 'anything']],
        ];

        self::assertSame([], self::fields($schema, ['n' => 1]));
    }

    #[Test]
    public function root_level_type_mismatch_is_reported(): void
    {
        $violations = ToolInputSchemaValidator::validate(['type' => 'object'], 'not-an-object');

        self::assertCount(1, $violations);
        self::assertSame('(arguments)', $violations[0]['field']);
    }
}
