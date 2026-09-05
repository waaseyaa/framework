<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Canonical source identity for the packaged skill inventory.
 *
 * Every generated guidance and per-skill file carries the same inventory hash
 * so a consumer can prove byte-for-byte regeneration from one canonical
 * source set (#2660).
 *
 * @api
 */
final readonly class SkillSourceProvenance
{
    /**
     * @param array<string, string> $sourceSha256BySkillId skill id => sha256 of the raw `SKILL.md` bytes
     */
    public function __construct(
        public array $sourceSha256BySkillId,
        public string $inventorySha256,
    ) {}

    public static function fromInventory(SkillInventory $inventory): self
    {
        $byId = $inventory->sourceSha256ById();
        ksort($byId);

        $lines = [];
        foreach ($byId as $id => $sha256) {
            $lines[] = $id . ':' . $sha256;
        }

        return new self(
            sourceSha256BySkillId: $byId,
            inventorySha256: hash('sha256', implode("\n", $lines)),
        );
    }

    /**
     * A single-line marker embedded inside managed regions.
     */
    public function managedRegionFooter(): string
    {
        return sprintf(
            '<!-- waaseyaa:bimaaji:source-inventory sha256=%s -->',
            $this->inventorySha256,
        );
    }
}
