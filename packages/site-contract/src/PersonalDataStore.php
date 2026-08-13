<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

/** @api */
final readonly class PersonalDataStore
{
    public function __construct(
        public string $id,
        public string $classification,
        public string $consentOperation,
        public string $retention,
        public string $exportOperation,
        public string $deletionOperation,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'classification' => $this->classification,
            'consent_operation' => $this->consentOperation,
            'retention' => $this->retention,
            'export_operation' => $this->exportOperation,
            'deletion_operation' => $this->deletionOperation,
        ];
    }
}
