<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provenance;

final readonly class InstalledWaaseyaaPackage
{
    public function __construct(
        public string $name,
        public string $lockedVersion,
        public string $sourceKind,
        public ?string $distUrl,
        public ?string $distReference,
        public ?string $resolvedPath,
        public ?string $gitHead,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'lockedVersion' => $this->lockedVersion,
            'sourceKind' => $this->sourceKind,
            'distUrl' => $this->distUrl,
            'distReference' => $this->distReference,
            'resolvedPath' => $this->resolvedPath,
            'gitHead' => $this->gitHead,
        ];
    }
}
