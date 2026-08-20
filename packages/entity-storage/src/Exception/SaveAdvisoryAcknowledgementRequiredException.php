<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Event\AbortOperationException;

/** Typed no-write outcome carrying advisories the caller must review. @api */
final class SaveAdvisoryAcknowledgementRequiredException extends AbortOperationException
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

        parent::__construct(
            'Review and acknowledge the save advisory before retrying.',
            self::class,
        );
    }

    /** @return non-empty-list<SaveAdvisory> */
    public function advisories(): array
    {
        return $this->advisoryValues;
    }

    /** @return non-empty-list<array{code:string,field:string,severity:string,message:string,acknowledgement:string}> */
    public function toArray(): array
    {
        return array_map(
            static fn(SaveAdvisory $advisory): array => $advisory->toArray(),
            $this->advisoryValues,
        );
    }
}
