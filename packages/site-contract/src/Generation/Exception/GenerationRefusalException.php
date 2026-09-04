<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation\Exception;

/**
 * A coded refusal from the generation execution and plan boundary.
 *
 * One exception carries a list of violations rather than one class per code,
 * for the reason ADR-025 D-6.4 already fixes: refusals are modelled as data in
 * a result envelope -- `errors` is a list -- so an exception carrying that same
 * list maps onto the envelope without translation. Fifteen exception classes
 * distinguished only by a constant would not.
 *
 * It extends `\RuntimeException` because this is an execution-boundary failure,
 * matching the uncoded `SiteInitializationCollisionException` and
 * `SiteInitializationLockedException` that D-5 names as today's equivalents for
 * two of these codes. The GEN0xx id is the code; the native integer code is
 * left at zero, exactly as the manifest-content family leaves it.
 */
final class GenerationRefusalException extends \RuntimeException
{
    /** @param list<GenerationViolation> $violations */
    public function __construct(
        public readonly string $source,
        public readonly array $violations,
        ?\Throwable $previous = null,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('Generation refusal source must not be empty.');
        }
        $first = $violations[0] ?? null;
        if ($first === null) {
            throw new \InvalidArgumentException('Generation refusal must carry at least one violation.');
        }
        parent::__construct(
            $first->path === null
                ? sprintf('%s %s: %s', $source, $first->code->value, $first->message)
                : sprintf('%s %s at %s: %s', $source, $first->code->value, $first->path, $first->message),
            0,
            $previous,
        );
    }

    /** @return list<array<string, string>> */
    public function toArray(): array
    {
        return array_map(
            static fn(GenerationViolation $violation): array => $violation->toArray(),
            $this->violations,
        );
    }
}
