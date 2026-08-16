<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;

#[CoversClass(ConfigSchemaValidator::class)]
final class ConfigSchemaValidatorTest extends TestCase
{
    private ConfigSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigSchemaValidator();
    }

    #[Test]
    public function direct_validation_meta_validates_the_schema_before_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported configuration schema keyword "coerce"');

        $this->validator->validate([], [
            'type' => 'object',
            'coerce' => true,
        ]);
    }

    #[Test]
    public function nullable_explicitly_distinguishes_null_from_wrong_type(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'subtitle' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        self::assertSame([], $this->validator->validate(['subtitle' => null], $schema));
        self::assertCount(1, $this->validator->validate(['subtitle' => false], $schema));
    }

    #[Test]
    public function nullable_keyword_must_be_boolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nullable');

        $this->validator->validate([], [
            'type' => 'object',
            'properties' => [
                'subtitle' => ['type' => 'string', 'nullable' => 'yes'],
            ],
        ]);
    }

    #[Test]
    public function validate_passes_with_valid_data(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
                'page_size' => ['type' => 'integer'],
            ],
        ];

        $data = ['site_name' => 'Waaseyaa', 'page_size' => 25];

        $violations = $this->validator->validate($data, $schema);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function validate_detects_wrong_type_string(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
            ],
        ];

        $data = ['site_name' => 12345];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertSame('site_name', $violations[0]->path);
        $this->assertStringContainsString('string', $violations[0]->message);
    }

    #[Test]
    public function validate_detects_wrong_type_integer(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'page_size' => ['type' => 'integer'],
            ],
        ];

        $data = ['page_size' => 'not-a-number'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertSame('page_size', $violations[0]->path);
        $this->assertStringContainsString('integer', $violations[0]->message);
    }

    #[Test]
    public function validate_detects_wrong_type_boolean(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'enabled' => ['type' => 'boolean'],
            ],
        ];

        $data = ['enabled' => 'yes'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertSame('enabled', $violations[0]->path);
    }

    #[Test]
    public function validate_rejects_unsupported_number_schema_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported configuration schema type "number"');

        $schema = [
            'type' => 'object',
            'properties' => [
                'ratio' => ['type' => 'number'],
            ],
        ];

        $this->validator->validate(['ratio' => 1], $schema);
    }

    #[Test]
    public function validate_integer_rejects_floating_point_values(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'integer'],
            ],
        ];

        $data = ['value' => 3.14];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
    }

    #[Test]
    public function validate_detects_wrong_type_array(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $data = ['tags' => 'not-an-array'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
    }

    #[Test]
    public function validate_detects_invalid_enum_value(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'registration' => [
                    'type' => 'string',
                    'enum' => ['open', 'admin_only', 'closed'],
                ],
            ],
        ];

        $data = ['registration' => 'maybe'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('enum', $violations[0]->message);
    }

    #[Test]
    public function validate_passes_valid_enum_value(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'registration' => [
                    'type' => 'string',
                    'enum' => ['open', 'admin_only', 'closed'],
                ],
            ],
        ];

        $data = ['registration' => 'admin_only'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function validate_detects_missing_required_property(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
                'slogan' => ['type' => 'string'],
            ],
            'required' => ['site_name'],
        ];

        $data = ['slogan' => 'The CMS'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('required', $violations[0]->message);
        $this->assertStringContainsString('site_name', $violations[0]->message);
    }

    #[Test]
    public function validate_handles_nested_object(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'database' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string'],
                        'port' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];

        $data = [
            'database' => [
                'host' => 'localhost',
                'port' => 'not-a-port',
            ],
        ];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertSame('database.port', $violations[0]->path);
    }

    #[Test]
    public function validate_enforces_minimum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'password_min_length' => [
                    'type' => 'integer',
                    'minimum' => 8,
                ],
            ],
        ];

        $data = ['password_min_length' => 3];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('minimum', $violations[0]->message);
    }

    #[Test]
    public function validate_enforces_maximum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'max_retries' => [
                    'type' => 'integer',
                    'maximum' => 10,
                ],
            ],
        ];

        $data = ['max_retries' => 15];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('maximum', $violations[0]->message);
    }

    #[Test]
    public function validate_passes_value_at_minimum_boundary(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'minimum' => 5],
            ],
        ];

        $data = ['count' => 5];

        $violations = $this->validator->validate($data, $schema);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function validate_rejects_extra_properties_without_additional_properties_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
            ],
        ];

        $data = ['site_name' => 'Waaseyaa', 'extra_key' => 'value'];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(1, $violations);
        $this->assertSame('extra_key', $violations[0]->path);
    }

    #[Test]
    public function validate_returns_multiple_violations(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
                'page_size' => ['type' => 'integer'],
                'enabled' => ['type' => 'boolean'],
            ],
        ];

        $data = [
            'site_name' => 123,
            'page_size' => 'abc',
            'enabled' => 'yes',
        ];

        $violations = $this->validator->validate($data, $schema);

        $this->assertCount(3, $violations);
    }

    #[Test]
    public function validate_with_default_fills_missing_value(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'registration' => [
                    'type' => 'string',
                    'default' => 'admin_only',
                ],
            ],
        ];

        // Empty data — default should apply and not cause a violation
        $data = [];

        $violations = $this->validator->validate($data, $schema);

        $this->assertSame([], $violations);
    }

    #[Test]
    public function has_schema_returns_false_when_no_schema_registered(): void
    {
        $this->assertFalse($this->validator->hasSchema('unknown.config'));
    }

    #[Test]
    public function has_schema_returns_true_after_registering(): void
    {
        $this->validator->registerSchema('system.site', [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
            ],
        ]);

        $this->assertTrue($this->validator->hasSchema('system.site'));
    }

    #[Test]
    public function validate_config_uses_registered_schema(): void
    {
        $this->validator->registerSchema('system.site', [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
            ],
        ]);

        $violations = $this->validator->validateConfig('system.site', ['site_name' => 123]);

        $this->assertCount(1, $violations);
    }

    #[Test]
    public function validate_config_throws_for_missing_schema(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No schema registered');

        $this->validator->validateConfig('unknown.config', []);
    }

    #[Test]
    public function get_schema_returns_registered_schema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
            ],
        ];

        $this->validator->registerSchema('system.site', $schema);

        $this->assertSame($schema, $this->validator->getSchema('system.site'));
    }

    #[Test]
    public function get_schema_returns_null_for_missing(): void
    {
        $this->assertNull($this->validator->getSchema('nonexistent'));
    }
}
