<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFieldType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}
