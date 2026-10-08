<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see \Waaseyaa\Listing\ExposedFilterCoercer} when a raw
 * URL-bound string cannot be coerced into the typed-data shape required by
 * a {@see \Waaseyaa\Listing\FilterDefinition}.
 *
 * Carries the offending raw value, the operator name (string-backed enum
 * value), the expected typed-data type identifier, and a human-readable
 * reason. Re-thrown unchanged by
 * {@see \Waaseyaa\Listing\ExposedFilterParser::parse()} in strict mode;
 * silently caught (and logged at debug level) in permissive mode.
 *
 * Internal refusal detail emitted by the public parser in strict mode.
 * {@see \Waaseyaa\Listing\ExposedFilterParser::strict()} configures that mode;
 * callers may catch this exception at their input boundary.
 *
 * @internal
 */
final class ListingCoercionException extends RuntimeException
{
    public function __construct(
        /** @api Input-boundary diagnostic read by strict-mode callers. */
        public readonly string $param,
        /** @api Input-boundary diagnostic read by strict-mode callers. */
        public readonly string $raw,
        /** @api Input-boundary diagnostic read by strict-mode callers. */
        public readonly string $operatorName,
        public readonly string $expectedType,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Coercion failure for exposed param "%s": raw=%s, operator=%s, type=%s — %s',
                $param,
                $raw,
                $operatorName,
                $expectedType,
                $reason,
            ),
            0,
            $previous,
        );
    }
}
