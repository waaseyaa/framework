<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/** @api */
final readonly class SqliteArtifactReport implements \JsonSerializable
{
    /** @param array<string, TableInstallEvidence> $tables */
    public function __construct(
        public int $catalogueVersion,
        public array $tables,
    ) {}

    /** @return array{catalogue_version:int,tables:array<string, TableInstallEvidence>} */
    public function jsonSerialize(): array
    {
        return [
            'catalogue_version' => $this->catalogueVersion,
            'tables' => $this->tables,
        ];
    }
}
