<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Security;

use Waaseyaa\Access\Capability\CapabilityReason;

/**
 * Reviewed, boot-visible field-read declaration owned by one CLI command.
 * CLI execution has no account principal; this metadata never grants ambient
 * account authority.
 *
 * @api
 */
final readonly class CliFieldReadCapabilityDeclaration
{
    /** @param list<string> $entityTypes @param list<string> $bundles @param list<string> $fields */
    public function __construct(
        public string $command,
        public CapabilityReason $reason,
        public array $entityTypes,
        public array $bundles,
        public array $fields,
        public string $justification,
        public ?string $tenantId = null,
        public ?string $communityId = null,
        public int $maxTtlSeconds = 300,
    ) {
        if ($command === '' || preg_match('/^[a-z][a-z0-9_-]*(?::[a-z][a-z0-9_-]*)+$/', $command) !== 1) {
            throw new \InvalidArgumentException('CLI field-read declarations require a canonical namespaced command.');
        }
        if ($entityTypes === [] || $bundles === [] || $fields === [] || trim($justification) === '' || $maxTtlSeconds < 1) {
            throw new \InvalidArgumentException('CLI field-read declarations require exact non-empty scope, justification, and TTL.');
        }
        foreach ([$entityTypes, $bundles, $fields] as $values) {
            if (in_array('*', $values, true) || count(array_unique($values)) !== count($values)) {
                throw new \InvalidArgumentException('First-party CLI field-read declarations cannot contain wildcards or duplicates.');
            }
        }
        if (!in_array($reason, [
            CapabilityReason::MaintenanceCli,
            CapabilityReason::AdminTooling,
            CapabilityReason::CredentialVerification,
            CapabilityReason::StrictAuditProjection,
        ], true)) {
            throw new \InvalidArgumentException('This capability reason is not valid for CLI command metadata.');
        }
    }

    public function issuer(): string
    {
        return 'cli:' . $this->reason->value . ':' . $this->command;
    }
}
