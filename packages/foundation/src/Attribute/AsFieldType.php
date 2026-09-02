<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

/**
 * @deprecated since #2786: field-type plugins are discovered through
 *   `Waaseyaa\Field\Attribute\FieldType` (the attribute they actually carry),
 *   which `PackageManifestCompiler` records under the manifest's `field_types`
 *   inventory. This marker is no longer read by discovery and admits nothing.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFieldType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}
