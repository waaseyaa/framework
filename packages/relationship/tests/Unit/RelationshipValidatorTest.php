<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Relationship\RelationshipValidator;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityTypeManager;

#[CoversClass(RelationshipValidator::class)]
final class RelationshipValidatorTest extends TestCase
{
    private function makeValidator(?EntityTypeManagerInterface $manager = null): RelationshipValidator
    {
        return new RelationshipValidator($manager ?? new StubEntityTypeManager([]));
    }

    // -----------------------------------------------------------------------
    // normalize()
    // -----------------------------------------------------------------------

    #[Test]
    public function normalize_trims_string_fields(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize([
            'relationship_type' => '  references  ',
            'from_entity_type' => ' node ',
            'source_ref' => '  http://example.com  ',
        ]);
        $this->assertSame('references', $result['relationship_type']);
        $this->assertSame('node', $result['from_entity_type']);
        $this->assertSame('http://example.com', $result['source_ref']);
    }

    #[Test]
    public function normalize_coerces_status_boolean_to_int(): void
    {
        $validator = $this->makeValidator();
        $this->assertSame(1, $validator->normalize(['status' => true])['status']);
        $this->assertSame(0, $validator->normalize(['status' => false])['status']);
    }

    #[Test]
    public function normalize_coerces_status_string_to_int(): void
    {
        $validator = $this->makeValidator();
        $this->assertSame(1, $validator->normalize(['status' => '1'])['status']);
        $this->assertSame(0, $validator->normalize(['status' => '0'])['status']);
        $this->assertSame(1, $validator->normalize(['status' => 'true'])['status']);
        $this->assertSame(0, $validator->normalize(['status' => 'false'])['status']);
    }

    #[Test]
    public function normalize_coerces_weight_string_to_float(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => '3.5']);
        $this->assertSame(3.5, $result['weight']);
    }

    #[Test]
    public function normalize_coerces_confidence_string_to_float(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['confidence' => '0.85']);
        $this->assertSame(0.85, $result['confidence']);
    }

    #[Test]
    public function normalize_leaves_null_optional_fields_as_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => null, 'confidence' => null]);
        $this->assertNull($result['weight']);
        $this->assertNull($result['confidence']);
    }

    #[Test]
    public function normalize_leaves_empty_string_optional_fields_unchanged(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => '', 'confidence' => '']);
        $this->assertSame('', $result['weight']);
        $this->assertSame('', $result['confidence']);
    }

    #[Test]
    public function normalize_converts_date_string_to_timestamp(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => '2025-01-15']);
        $this->assertIsInt($result['start_date']);
        $this->assertGreaterThan(0, $result['start_date']);
    }

    #[Test]
    public function normalize_passes_through_date_int(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => 1700000000]);
        $this->assertSame(1700000000, $result['start_date']);
    }

    #[Test]
    public function normalize_converts_numeric_date_string_to_int(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['end_date' => '1700000000']);
        $this->assertSame(1700000000, $result['end_date']);
    }

    #[Test]
    public function normalize_converts_empty_date_string_to_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => '', 'end_date' => '  ']);
        $this->assertNull($result['start_date']);
        $this->assertNull($result['end_date']);
    }

    #[Test]
    public function normalize_converts_null_date_to_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => null]);
        $this->assertNull($result['start_date']);
    }

    #[Test]
    public function normalize_is_idempotent(): void
    {
        $validator = $this->makeValidator();
        $input = [
            'relationship_type' => '  references  ',
            'status' => 'true',
            'weight' => '2.5',
            'start_date' => '2025-01-01',
        ];
        $first = $validator->normalize($input);
        $second = $validator->normalize($first);
        $this->assertSame($first, $second);
    }

    // -----------------------------------------------------------------------
    // validate()
    // -----------------------------------------------------------------------

    #[Test]
    public function validate_returns_errors_for_all_missing_required_fields(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([]);
        $this->assertNotEmpty($errors);
        $requiredFields = ['relationship_type', 'from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'directionality', 'status'];
        foreach ($requiredFields as $field) {
            $found = false;
            foreach ($errors as $error) {
                if (str_contains($error, sprintf('"%s"', $field))) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected error for required field '$field'");
        }
    }

    #[Test]
    public function validate_accepts_valid_complete_entity(): void
    {
        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_invalid_directionality(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'sideways',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'directionality')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected directionality validation error');
    }

    #[Test]
    public function validate_rejects_invalid_relationship_type_format(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'Invalid-Type!',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'relationship_type') && str_contains($error, 'match')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected relationship_type format validation error');
    }

    #[Test]
    public function validate_rejects_confidence_out_of_range(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 1.5,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'confidence')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected confidence out-of-range error');
    }

    #[Test]
    public function validate_accepts_confidence_in_range(): void
    {
        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 0.5,
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_non_numeric_confidence(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 'high',
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'confidence')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected confidence non-numeric error');
    }

    #[Test]
    public function validate_rejects_start_date_after_end_date(): void
    {
        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'start_date' => 2000,
            'end_date' => 1000,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'start_date') && str_contains($error, 'end_date')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected start_date > end_date error');
    }

    #[Test]
    public function validate_rejects_unparseable_date_string(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'start_date' => 'not-a-date-$$',
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'start_date')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected unparseable start_date error');
    }

    #[Test]
    public function validate_rejects_unknown_entity_type(): void
    {
        $manager = new StubEntityTypeManager([]);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'nonexistent',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'unknown entity type')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected unknown entity type error');
    }

    #[Test]
    public function validate_rejects_invalid_status_value(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 99,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'status')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected invalid status error');
    }

    // -----------------------------------------------------------------------
    // assertValid()
    // -----------------------------------------------------------------------

    #[Test]
    public function assert_valid_does_not_throw_for_valid_entity(): void
    {
        $manager = new StubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $this->expectNotToPerformAssertions();
        $validator->assertValid([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }

    #[Test]
    public function assert_valid_throws_for_invalid_entity(): void
    {
        $validator = $this->makeValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship validation failed');
        $validator->assertValid([]);
    }
}
