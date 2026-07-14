<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Source;

use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * Lazily narrow a source stream while preserving its identity semantics.
 *
 * Fixed-bundle migration definitions use this decorator when one external
 * source contains records for several destination bundles. The wrapped
 * source remains authoritative for stability and SourceId construction;
 * only record selection and the discovery-facing plugin id are changed.
 *
 * @api
 */
final readonly class FilteredSource implements SourcePluginInterface
{
    /**
     * @param \Closure(SourceRecord): bool $accept
     */
    public function __construct(
        private SourcePluginInterface $source,
        private \Closure $accept,
        private string $pluginId,
    ) {
        if ($pluginId === '') {
            throw new \InvalidArgumentException('FilteredSource::$pluginId must be a non-empty string.');
        }
    }

    public function id(): string
    {
        return $this->pluginId;
    }

    public function stability(): string
    {
        return $this->source->stability();
    }

    public function records(): iterable
    {
        foreach ($this->source->records() as $record) {
            if (($this->accept)($record)) {
                yield $record;
            }
        }
    }

    public function sourceIdFor(SourceRecord $record): SourceId
    {
        return $this->source->sourceIdFor($record);
    }

    public function count(): ?int
    {
        return null;
    }
}
