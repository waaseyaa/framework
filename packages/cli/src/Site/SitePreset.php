<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

/**
 * `minimal` and `editorial` are init-time-only shortcuts for `site:init`
 * (#2442, ADR-024 D-3/D-4): each resolves, once, to a concrete
 * `waaseyaa.site` decision set through {@see SitePresetResolver}. Nothing
 * downstream reads this enum or a serialized form of it — it never reaches
 * `SiteManifestSchema`, `.waaseyaa/site.yaml`, or `.waaseyaa/generated.json`.
 *
 * @internal
 */
enum SitePreset: string
{
    case Minimal = 'minimal';
    case Editorial = 'editorial';

    public static function fromCliValue(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf(
                "Unknown site:init preset '%s'. Use 'minimal' or 'editorial'.",
                $value,
            ));
    }
}
