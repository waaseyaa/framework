<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

final readonly class CapabilityRequirement
{
    public function __construct(
        public string $id,
        public int $minimumVersion,
        public int $maximumVersion,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]+$/D', $id) !== 1
            || $minimumVersion < 1
            || $maximumVersion < $minimumVersion
        ) {
            throw new \InvalidArgumentException('Capability requirements require a stable id and valid version range.');
        }
    }

    public static function exact(string $id, int $version): self
    {
        return new self($id, $version, $version);
    }

    public function accepts(CapabilityDeclaration $declaration): bool
    {
        return $declaration->id === $this->id
            && $declaration->version >= $this->minimumVersion
            && $declaration->version <= $this->maximumVersion;
    }
}
