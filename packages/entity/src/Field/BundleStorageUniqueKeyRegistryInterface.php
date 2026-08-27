<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Field;

/**
 * Registry for named storage-level unique keys scoped to one entity bundle.
 *
 * @internal Capability consumed by EntityTypeManager and SQL storage.
 */
interface BundleStorageUniqueKeyRegistryInterface
{
    /**
     * @param list<array{name: string, fields: non-empty-list<string>}> $keys
     */
    public function registerBundleUniqueKeys(string $entityTypeId, string $bundle, array $keys): void;

    /**
     * @return list<array{name: string, fields: non-empty-list<string>}>
     */
    public function bundleUniqueKeysFor(string $entityTypeId, string $bundle): array;
}
