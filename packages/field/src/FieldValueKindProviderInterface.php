<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/**
 * Optional presentation capability for field-type plugins.
 *
 * FieldTypeInterface implementors remain source-compatible. A plugin opts into
 * generic wire adapters by implementing this interface; adapters fail closed
 * when the capability is absent.
 *
 * @api
 */
interface FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind;
}
