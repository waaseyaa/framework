<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;

/** Records every audit descriptor for assertion. */
final class SpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $records = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->records[] = $descriptor;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_map(static fn(AuditEventDescriptor $d): string => $d->kind->value, $this->records);
    }
}
