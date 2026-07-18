<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Security;

/** Exact privileged reads reviewed for one migration manifest. @api */
final readonly class MigrationFieldReadManifest
{
    /** @param list<string> $entityTypes @param list<string> $bundles @param list<string> $fields */
    public function __construct(
        public string $migrationId,
        public array $entityTypes,
        public array $bundles,
        public array $fields,
        public string $justification,
        public ?string $tenantId = null,
        public ?string $communityId = null,
        public int $maxTtlSeconds = 300,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $migrationId) !== 1 || $entityTypes === [] || $bundles === [] || $fields === [] || trim($justification) === '' || $maxTtlSeconds < 1) {
            throw new \InvalidArgumentException('Migration field-read manifests require an id, exact scope, justification, and TTL.');
        }
        foreach ([$entityTypes, $bundles, $fields] as $values) {
            if (in_array('*', $values, true) || count(array_unique($values)) !== count($values)) {
                throw new \InvalidArgumentException('First-party migration field-read manifests cannot contain wildcards or duplicates.');
            }
        }
    }

    public function issuer(): string
    {
        return 'migration:' . $this->migrationId;
    }
}
