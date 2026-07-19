<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/** Exact immutable authorization-input view produced by the sealed entity container. @internal */
final readonly class CompiledPolicySubjectView implements PolicySubjectViewInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function fields(): array
    {
        return array_keys($this->values);
    }

    public function get(string $fieldName): mixed
    {
        if (!array_key_exists($fieldName, $this->values)) {
            throw new \LogicException(sprintf('Field "%s" is not an authorization input for this read.', $fieldName));
        }

        return $this->values[$fieldName];
    }
}
