<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Package-owned validation for constraints the closed schema dialect cannot express. @api */
interface ConfigSemanticValidatorInterface
{
    /**
     * @param array<string, mixed> $fields Structurally valid authored fields
     * @return list<SchemaViolation>
     */
    public function validate(array $fields): array;
}
