<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Definition;

/** @api */
final readonly class BlockDefinition
{
    /** @param array<string, mixed> $configSchema */
    public function __construct(
        public string $id,
        public int $version,
        public string $label,
        public string $renderer,
        public array $configSchema,
    ) {
        DefinitionIdentity::assert($id, $version, 'block');
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('Block label must not be empty');
        }
        DefinitionIdentity::assertId($renderer, 'block renderer');
        if ([] === $configSchema) {
            throw new \InvalidArgumentException('Block configuration schema must not be empty');
        }
    }
}
