<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

use Waaseyaa\SiteContract\CanonicalJson;

/**
 * A transport-neutral description, not authority to execute an operation.
 * Construct through ManagementManifestParser::operation() to validate the shape.
 * @api
 */
final readonly class ManagementOperation
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $id, public string $capabilityId, public array $metadata) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'capability' => $this->capabilityId] + $this->metadata;
    }

    public function digest(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }
}
