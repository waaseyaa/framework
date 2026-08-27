<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** A database-enforced unique-key collision on one bundle subtable. @api */
final class BundleUniqueKeyConflictException extends \RuntimeException
{
    public readonly string $errorCode;

    /**
     * @param non-empty-list<string> $fields
     * @param array<string, mixed> $values
     */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $bundle,
        public readonly string $keyName,
        public readonly array $fields,
        public readonly array $values,
        ?\Throwable $previous = null,
    ) {
        $this->errorCode = 'BUNDLE_UNIQUE_KEY_CONFLICT';
        parent::__construct(\sprintf(
            'Bundle unique key "%s" conflicts for entity type "%s" bundle "%s" on %s.',
            $keyName,
            $entityTypeId,
            $bundle,
            \implode(', ', \array_map(
                static fn(string $field): string => \sprintf('%s=%s', $field, \json_encode($values[$field] ?? null, JSON_THROW_ON_ERROR)),
                $fields,
            )),
        ), 0, $previous);
    }
}
