<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** Refuses bundle unique-key materialization when existing rows conflict. @api */
final class BundleUniqueKeyMigrationException extends \RuntimeException
{
    public readonly string $errorCode;

    /** @param non-empty-list<string> $fields */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $bundle,
        public readonly string $keyName,
        public readonly array $fields,
    ) {
        $this->errorCode = 'bundle_unique_key_duplicates';
        parent::__construct(\sprintf(
            '[bundle_unique_key_duplicates] Cannot materialize unique key "%s" on entity type "%s" bundle "%s": existing non-null values for fields [%s] are duplicated. Resolve duplicates and rerun `waaseyaa schema:sync`.',
            $keyName,
            $entityTypeId,
            $bundle,
            \implode(', ', $fields),
        ));
    }
}
