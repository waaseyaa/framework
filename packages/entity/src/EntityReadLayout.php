<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Entity\Exception\StaleEntityReadLayout;

/** Immutable compiled field classification and its process-generation seal. @api */
final readonly class EntityReadLayout
{
    /** @var array<string, FieldReadLevel> */
    private array $levels;

    /** @var array<string, CompiledFieldReadRule> */
    private array $rules;

    /** @var list<string> */
    private array $authorizationInputs;

    /** @var array<string, list<string>> */
    private array $authorizationInputsWithoutSelf;

    private int $sealedGeneration;

    /** @var array<int, array{generation: EntityReadLayoutGeneration, sealed: int}> */
    private array $additionalGenerationSeals;

    private string $fingerprint;

    /**
     * @param array<string, FieldReadLevel> $levels
     * @param list<string> $authorizationInputs
     * @param list<EntityReadLayoutGeneration> $additionalGenerations
     */
    public function __construct(
        private EntityReadLayoutGeneration $generation,
        array $levels,
        array $authorizationInputs = [],
        private FieldReadLevel $undeclaredLevel = FieldReadLevel::Internal,
        array $additionalGenerations = [],
    ) {
        ksort($levels);
        foreach ($levels as $field => $level) {
            if ($field === '') {
                throw new \InvalidArgumentException('Entity read layouts require named FieldReadLevel entries.');
            }
        }
        $this->levels = $levels;
        $rules = [];
        foreach ($levels as $field => $level) {
            $rules[$field] = new CompiledFieldReadRule($field, $level);
        }
        $this->rules = $rules;
        $this->authorizationInputs = $authorizationInputs;
        $authorizationInputsWithoutSelf = [];
        foreach ($this->authorizationInputs as $releasedField) {
            $authorizationInputsWithoutSelf[$releasedField] = array_values(array_filter(
                $this->authorizationInputs,
                static fn(string $field): bool => $field !== $releasedField,
            ));
        }
        $this->authorizationInputsWithoutSelf = $authorizationInputsWithoutSelf;
        $this->sealedGeneration = $generation->current();
        $additionalGenerationSeals = [];
        foreach ($additionalGenerations as $additionalGeneration) {
            $additionalGenerationSeals[] = [
                'generation' => $additionalGeneration,
                'sealed' => $additionalGeneration->current(),
            ];
        }
        $this->additionalGenerationSeals = $additionalGenerationSeals;
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
        return $this->authorizationInputsWithoutSelf[$releasedField] ?? $this->authorizationInputs;
    }

    public function rule(string $field): CompiledFieldReadRule
    {
        return $this->rules[$field] ?? new CompiledFieldReadRule($field, $this->undeclaredLevel);
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
        foreach ($this->additionalGenerationSeals as $seal) {
            if ($seal['generation']->current() !== $seal['sealed']) {
                throw new StaleEntityReadLayout('The entity was sealed under an obsolete field-read layout and must be reloaded.');
            }
        }
    }
}
