<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Process;

use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Plugin\ProcessContext;
use Waaseyaa\Migration\Plugin\ProcessPluginInterface;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Resolve a list whose items belong to different migration id-map partitions.
 *
 * Source-format wiring supplies callbacks that route each item to a migration
 * id and construct its SourceId. This keeps taxonomy names, MIME types, and
 * other external-system concepts outside the framework while allowing a
 * fixed-bundle import to resolve one mixed reference field without a custom
 * process-plugin class.
 *
 * @api
 */
final readonly class PartitionedLookupProcessor implements ProcessPluginInterface
{
    public const string ON_MISS_NULL = 'null';
    public const string ON_MISS_FAIL = 'fail';

    /**
     * @param \Closure(mixed): ?string $migrationFor
     * @param \Closure(mixed): ?SourceId $sourceIdFor
     * @param (\Closure(WriteResult): mixed)|null $resultFor Defaults to the destination UUID.
     */
    public function __construct(
        public string $sourceField,
        private \Closure $migrationFor,
        private \Closure $sourceIdFor,
        private ?\Closure $resultFor = null,
        public string $onMiss = self::ON_MISS_NULL,
    ) {
        if ($sourceField === '') {
            throw new \InvalidArgumentException('PartitionedLookupProcessor::$sourceField must be a non-empty string.');
        }
        if ($onMiss !== self::ON_MISS_NULL && $onMiss !== self::ON_MISS_FAIL) {
            throw new \InvalidArgumentException(\sprintf(
                'PartitionedLookupProcessor::$onMiss must be %s or %s, got %s.',
                var_export(self::ON_MISS_NULL, true),
                var_export(self::ON_MISS_FAIL, true),
                var_export($onMiss, true),
            ));
        }
    }

    public function id(): string
    {
        return ReservedPluginIds::PARTITIONED_LOOKUP;
    }

    public function stability(): string
    {
        return 'stable';
    }

    /** @return list<mixed> */
    public function transform(mixed $value, ProcessContext $context): array
    {
        $items = $value ?? $context->sourceRecord->field($this->sourceField, []);
        if (!is_array($items)) {
            return $this->miss($context, 'Partitioned lookup requires a list source value.');
        }

        $resolved = [];
        foreach ($items as $item) {
            $migrationId = ($this->migrationFor)($item);
            $sourceId = ($this->sourceIdFor)($item);
            if (!is_string($migrationId) || $migrationId === '' || !$sourceId instanceof SourceId) {
                $this->miss($context, 'No migration partition or source identity was provided for a list item.');
                continue;
            }

            $result = ($context->lookup)($migrationId, $sourceId);
            if (!$result instanceof WriteResult) {
                $this->miss($context, \sprintf('No id-map row in migration %s for a list item.', var_export($migrationId, true)));
                continue;
            }

            $mapped = $this->resultFor === null
                ? $result->destinationUuid
                : ($this->resultFor)($result);
            if ($mapped === null) {
                $this->miss($context, \sprintf('The destination reference mapper missed for migration %s.', var_export($migrationId, true)));
                continue;
            }

            $resolved[] = $mapped;
        }

        return $resolved;
    }

    /** @return list<never> */
    private function miss(ProcessContext $context, string $message): array
    {
        if ($this->onMiss === self::ON_MISS_FAIL) {
            throw new ProcessException(
                processCode: ProcessException::CODE_LOOKUP_MISS,
                sourceField: $this->sourceField,
                migrationId: $context->migrationId,
                message: 'PartitionedLookupProcessor: ' . $message,
            );
        }

        return [];
    }
}
