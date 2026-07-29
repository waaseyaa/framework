<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

/**
 * App-supplied editorial rule evaluated on every draft/update/publish payload.
 *
 * Implementations append field-specific errors (e.g. "no em dashes",
 * "alt text required when an image is set"). They must be pure functions of
 * the payload — no I/O, no mutation.
 *
 * @api
 */
interface ContentValidatorInterface
{
    /** @param array<string, mixed> $values The full effective payload (existing values overlaid with the mutation). */
    public function validate(array $values, ValidationErrors $errors): void;
}
