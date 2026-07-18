<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Validation\ValidationFieldReader;
use Waaseyaa\Entity\Validation\ValidationReadLedgerInterface;
use Waaseyaa\Entity\Validation\ValidationReadReservationInterface;

/** Test-only wiring for the closed non-Public validation boundary. */
final class ClosedEntityValidatorFactory
{
    public static function create(ValidatorInterface $validator): EntityValidator
    {
        $ledger = new class implements ValidationReadLedgerInterface {
            public function reserve(EntityStructure $subject, string $field): ValidationReadReservationInterface
            {
                return new class implements ValidationReadReservationInterface {
                    public function finalize(bool $success): void {}
                };
            }
        };

        return new EntityValidator($validator, new ValidationFieldReader($ledger));
    }
}
