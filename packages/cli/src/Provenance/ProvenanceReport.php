<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provenance;

final readonly class ProvenanceReport
{
    /**
     * @param array<string, string> $constraints
     * @param list<string> $uniqueConstraints
     * @param list<InstalledWaaseyaaPackage> $packages
     * @param list<string> $driftMessages
     */
    public function __construct(
        public ?string $goldenSha,
        public array $constraints,
        public array $uniqueConstraints,
        public array $packages,
        public ?string $pathMonorepoHead,
        public array $driftMessages,
        private string $projectRoot = '',
    ) {}

    public function projectRootDisplay(): string
    {
        return $this->projectRoot;
    }

    public function hasDrift(): bool
    {
        return $this->driftMessages !== [];
    }

    public function hasPathInstalls(): bool
    {
        foreach ($this->packages as $p) {
            if ($p->sourceKind === 'path') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'projectRoot' => $this->projectRoot,
            'goldenSha' => $this->goldenSha,
            'pathMonorepoHead' => $this->pathMonorepoHead,
            'uniqueConstraintPatterns' => $this->uniqueConstraints,
            'constraints' => $this->constraints,
            'packages' => array_map(static fn (InstalledWaaseyaaPackage $p) => $p->toArray(), $this->packages),
            'drift' => $this->driftMessages,
            'hasDrift' => $this->hasDrift(),
        ];
    }
}
