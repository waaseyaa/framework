<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Exception\StaleEntityReadLayout;

/** Immutable compiled field classification and its process-generation seal. @api */
final readonly class EntityReadLayout
{
    /** @var array<string, FieldReadLevel> */
    private array $levels;

    private int $sealedGeneration;

    private string $fingerprint;

    /** @param array<string, FieldReadLevel> $levels @param list<string> $authorizationInputs */
    public function __construct(
        private EntityReadLayoutGeneration $generation,
        array $levels,
        private array $authorizationInputs = [],
        private FieldReadLevel $undeclaredLevel = FieldReadLevel::Internal,
    ) {
        ksort($levels);
        foreach ($levels as $field => $level) {
            if ($field === '') {
                throw new \InvalidArgumentException('Entity read layouts require named FieldReadLevel entries.');
            }
        }
        $this->levels = $levels;
        $this->sealedGeneration = $generation->current();
        $fingerprintLevels = array_map(
            static fn(FieldReadLevel $level): string => $level->value,
            $levels,
        );
        $fingerprintLevels["\0undeclared"] = $this->undeclaredLevel->value;
        $this->fingerprint = hash('xxh128', json_encode($fingerprintLevels, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function authorizationInputsFor(string $releasedField): array
    {
        return array_values(array_filter(
            $this->authorizationInputs,
            static fn(string $field): bool => $field !== $releasedField,
        ));
    }

    public function level(string $field): FieldReadLevel
    {
        return $this->levels[$field] ?? $this->undeclaredLevel;
    }

    /** @return array<string, FieldReadLevel> */
    public function levels(): array
    {
        return $this->levels;
    }

    public function generation(): int
    {
        return $this->sealedGeneration;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function assertCurrent(): void
    {
        if ($this->generation->current() !== $this->sealedGeneration) {
            throw new StaleEntityReadLayout('The entity was sealed under an obsolete field-read layout and must be reloaded.');
        }
    }
}
