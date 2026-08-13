<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Definition;

/** @api */
final class DefinitionIdentity
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D';

    public static function assert(string $id, int $version, string $kind): void
    {
        self::assertId($id, $kind);
        if ($version < 1) {
            throw new \InvalidArgumentException(ucfirst($kind) . ' version must be a positive integer');
        }
    }

    public static function assertId(string $id, string $kind): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new \InvalidArgumentException("Invalid {$kind} id: {$id}");
        }
    }

    /** @param list<string> $ids */
    public static function assertUniqueIds(array $ids, string $kind): void
    {
        $seen = [];
        foreach ($ids as $id) {
            self::assertId($id, $kind);
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException("Duplicate {$kind} id: {$id}");
            }
            $seen[$id] = true;
        }
    }
}
