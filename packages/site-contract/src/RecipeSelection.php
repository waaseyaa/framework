<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

/** @api */
final readonly class RecipeSelection
{
    public function __construct(
        public string $id,
        public int $version,
        public string $capability,
        public string $artifactDigest,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'capability' => $this->capability,
            'artifact_digest' => $this->artifactDigest,
        ];
    }
}
