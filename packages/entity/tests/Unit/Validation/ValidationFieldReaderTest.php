<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Validation\RedactedInvalidValue;
use Waaseyaa\Entity\Validation\ValidationFieldReader;
use Waaseyaa\Entity\Validation\ValidationReadLedgerInterface;
use Waaseyaa\Entity\Validation\ValidationReadReservationInterface;

final class ValidationFieldReaderTest extends TestCase
{
    #[Test]
    public function non_public_validation_is_reserved_and_redacts_the_invalid_value(): void
    {
        $ledger = new RecordingValidationLedger();
        $validator = new EntityValidator(
            Validation::createValidator(),
            new ValidationFieldReader($ledger),
        );

        $violations = $validator->validate($this->sealedEntity(''), [
            'mail' => [new NotBlank()],
        ]);

        self::assertCount(1, $violations);
        self::assertSame('mail', $violations[0]->getPropertyPath());
        self::assertSame(RedactedInvalidValue::Value, $violations[0]->getInvalidValue());
        self::assertSame([['mail', true]], $ledger->finalizations);
    }

    #[Test]
    public function custom_non_public_constraint_is_rejected_before_reservation_or_value_read(): void
    {
        $ledger = new RecordingValidationLedger();
        $validator = new EntityValidator(
            Validation::createValidator(),
            new ValidationFieldReader($ledger),
        );

        try {
            $validator->validate($this->sealedEntity('member@example.test'), [
                'mail' => [new Callback(static function (): void {})],
            ]);
            self::fail('Custom validation must be rejected for non-Public fields.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString(Callback::class, $exception->getMessage());
        }
        self::assertSame([], $ledger->finalizations);
    }

    #[Test]
    public function no_outward_violation_surface_retains_the_non_public_value(): void
    {
        $secret = 'restricted-value-sentinel';
        $ledger = new RecordingValidationLedger();
        $violations = new EntityValidator(
            Validation::createValidator(),
            new ValidationFieldReader($ledger),
        )->validate($this->sealedEntity($secret), ['mail' => [new Email()]]);

        self::assertCount(1, $violations);
        $violation = $violations[0];
        $outward = [
            $violation->getMessage(),
            $violation->getMessageTemplate(),
            $violation->getParameters(),
            $violation->getRoot(),
            $violation->getInvalidValue(),
            $violation->getPlural(),
            $violation->getCode(),
            $violation->getConstraint(),
            $violation->getCause(),
        ];

        self::assertStringNotContainsString($secret, serialize($outward));
    }

    private function sealedEntity(string $mail): ValidationEntity
    {
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            ['id' => 1, 'mail' => $mail],
            new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'mail' => FieldReadLevel::Internal,
            ]),
            new EntityStructure('validation', 'validation', 1, null, fieldNames: ['id', 'mail']),
            'validation',
            ['id' => 'id'],
        );
        $entity = $boundary->installer()->instantiate(ValidationEntity::class, $payload);
        self::assertInstanceOf(ValidationEntity::class, $entity);

        return $entity;
    }
}

final class RecordingValidationLedger implements ValidationReadLedgerInterface
{
    /** @var list<array{string, bool}> */
    public array $finalizations = [];

    public function reserve(EntityStructure $subject, string $field): ValidationReadReservationInterface
    {
        return new class ($this, $field) implements ValidationReadReservationInterface {
            public function __construct(private RecordingValidationLedger $ledger, private string $field) {}

            public function finalize(bool $success): void
            {
                $this->ledger->finalizations[] = [$this->field, $success];
            }
        };
    }
}

final class ValidationEntity extends EntityBase {}
