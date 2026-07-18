<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Preflight;

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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data->canonicalData() + [
            'ready' => $this->ready,
            'checksum' => $this->checksum,
        ];
    }
}
