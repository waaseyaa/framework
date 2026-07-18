<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Preflight;

use Waaseyaa\Entity\Exception\FieldAccessActivationBlocked;

/** @api */
final readonly class FieldAccessPreflightResult
{
    private function __construct(
        public FieldAccessPreflightData $data,
        public bool $ready,
        public string $checksum,
    ) {}

    public static function fromData(FieldAccessPreflightData $data): self
    {
        $canonical = json_encode($data->canonicalData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self(
            data: $data,
            ready: $data->conflicts === []
                && $data->unclassifiedEntries === []
                && $data->v1Drivers === []
                && $data->serializedEntities === []
                && $data->legacyPayloads === [],
            checksum: hash('sha256', $canonical),
        );
    }

    public function assertReadyForActivation(): void
    {
        if ($this->ready) {
            return;
        }

        throw new FieldAccessActivationBlocked(sprintf(
            'Field-read activation blocked: conflicts=%d unclassified=%d v1_drivers=%d serialized_entities=%d legacy_payloads=%d (checksum %s).',
            count($this->data->conflicts),
            count($this->data->unclassifiedEntries),
            count($this->data->v1Drivers),
            count($this->data->serializedEntities),
            count($this->data->legacyPayloads),
            $this->checksum,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data->canonicalData() + [
            'ready' => $this->ready,
            'checksum' => $this->checksum,
        ];
    }
}
