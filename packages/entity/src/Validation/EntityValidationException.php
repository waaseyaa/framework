<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class EntityValidationException extends \RuntimeException
{
    public function __construct(
        public readonly ConstraintViolationListInterface $violations,
        string $message = 'Entity validation failed.',
    ) {
        parent::__construct($message);
    }
}
