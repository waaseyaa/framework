<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Optional capability for writing one language peer through the storage boundary. @api */
interface LangcodePeerStorageDriverV2Interface
{
    public function assertLangcodePeerMutationAllowed(
        string $entityType,
        string $id,
        string $langcode,
        string $defaultLangcode,
        StorageSnapshot $snapshot,
    ): void;

    public function writeLangcodePeer(
        string $entityType,
        string $id,
        string $langcode,
        string $defaultLangcode,
        StorageSnapshot $snapshot,
    ): void;
}
