<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Exception;

final class UnknownFieldTypeException extends \DomainException
{
    public static function for(string $fieldType): self
    {
        return new self(sprintf('No registered field-type schema authority exists for "%s".', $fieldType));
    }
}
