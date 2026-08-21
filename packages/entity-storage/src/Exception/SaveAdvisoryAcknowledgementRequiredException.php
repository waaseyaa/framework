<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;

/**
 * Typed no-write outcome carrying advisories the caller must review.
 *
 * Sibling of {@see \Waaseyaa\EntityStorage\Event\AbortOperationException}, not a
 * subclass: throwing from `BeforeSaveEvent` still performs no backend write,
 * but existing abort catches must not silently absorb an unacknowledged advisory.
 *
 * @api
 */
final class SaveAdvisoryAcknowledgementRequiredException extends \RuntimeException
{
    /** @param array<int, mixed> $advisoryValues */
    public function __construct(private readonly array $advisoryValues)
    {
        if ($advisoryValues === []) {
            throw new \InvalidArgumentException('At least one unacknowledged save advisory is required.');
        }
        foreach ($advisoryValues as $advisory) {
            if (!$advisory instanceof SaveAdvisory) {
                throw new \InvalidArgumentException('Save advisory exceptions require only SaveAdvisory values.');
            }
        }

        parent::__construct('Review and acknowledge the save advisory before retrying.');
    }

    /** @return non-empty-list<SaveAdvisory> */
    public function advisories(): array
    {
        return $this->advisoryValues;
    }

    /** @return non-empty-list<array{code:string,field:string,severity:string,message:string,acknowledgement:string}> */
    public function advisoryPayloads(): array
    {
        return array_map(
            static fn(SaveAdvisory $advisory): array => $advisory->payload(),
            $this->advisoryValues,
        );
    }
}
