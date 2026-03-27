<?php

declare(strict_types=1);

namespace Waaseyaa\Ingestion\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Ingestion\EnvelopeValidator;
use Waaseyaa\Ingestion\PayloadValidatorInterface;
use Waaseyaa\Ingestion\ValidationResult;

#[CoversClass(ValidationResult::class)]
final class PayloadValidatorInterfaceTest extends TestCase
{
    #[Test]
    public function implementationCanReturnValidResult(): void
    {
        $validator = new class implements PayloadValidatorInterface {
            public function validate(array $envelope): ValidationResult
            {
                return new ValidationResult();
            }
        };

        $result = $validator->validate(['any' => 'data']);
        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function implementationCanReturnInvalidResult(): void
    {
        $validator = new class implements PayloadValidatorInterface {
            public function validate(array $envelope): ValidationResult
            {
                $errors = [];
                if (!isset($envelope['required_key'])) {
                    $errors[] = 'Missing required_key';
                }
                return new ValidationResult($errors);
            }
        };

        $result = $validator->validate([]);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('required_key', $result->errors[0]);
    }

    #[Test]
    public function envelopeValidatorSatisfiesInterface(): void
    {
        $this->assertTrue(
            is_subclass_of(
                EnvelopeValidator::class,
                PayloadValidatorInterface::class,
            ),
        );
    }
}
