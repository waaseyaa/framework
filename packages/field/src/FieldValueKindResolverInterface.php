<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/**
 * Optional registry capability for resolving field presentation kinds.
 *
 * @api
 */
interface FieldValueKindResolverInterface
{
    public function valueKind(string $fieldType): FieldValueKind;
}
