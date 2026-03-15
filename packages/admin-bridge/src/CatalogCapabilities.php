<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class CatalogCapabilities
{
    public function __construct(
        public bool $list = true,
        public bool $get = true,
        public bool $create = false,
        public bool $update = false,
        public bool $delete = false,
        public bool $schema = true,
    ) {}

    /** @return array{list: bool, get: bool, create: bool, update: bool, delete: bool, schema: bool} */
    public function toArray(): array
    {
        return [
            'list' => $this->list,
            'get' => $this->get,
            'create' => $this->create,
            'update' => $this->update,
            'delete' => $this->delete,
            'schema' => $this->schema,
        ];
    }
}
