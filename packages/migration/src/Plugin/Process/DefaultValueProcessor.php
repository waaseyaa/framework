<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Process;

use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;

/**
 * Substitute a fallback when the chained value is null (and optionally when
 * it is the empty string).
 *
 * A small framework-reserved process plugin. Usually the
 * last link in a chain, so a downstream destination can rely on a
 * non-null value.
 *
 * `$treatEmptyStringAsNull` defaults to `true` because most source systems
 * round-trip "missing" as `""` rather than `null` (CSV columns, HTML form
 * data, etc.). Set it to `false` when the destination field semantically
 * distinguishes "empty string" from "absent".
 *
 * @api
 *
 * @spec FR-010 — framework-reserved process plugin (`default_value`)
 */
final readonly class DefaultValueProcessor implements ProcessPluginInterface
{
    /**
     * @param mixed $default Value substituted when the input is null (or empty string, when configured).
     * @param bool $treatEmptyStringAsNull When true, `''` is treated as null and replaced by `$default`.
     */
    public function __construct(
        public mixed $default,
        public bool $treatEmptyStringAsNull = true,
    ) {}

    public function id(): string
    {
        return ReservedPluginIds::DEFAULT_VALUE;
    }

    public function stability(): string
    {
        return 'stable';
    }

    public function transform(mixed $value, ProcessContext $context): mixed
    {
        if ($value === null) {
            return $this->default;
        }

        if ($this->treatEmptyStringAsNull && $value === '') {
            return $this->default;
        }

        return $value;
    }
}
