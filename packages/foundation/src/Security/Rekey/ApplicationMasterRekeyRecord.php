<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Current non-secret request projection backed by immutable events. @api */
final readonly class ApplicationMasterRekeyRecord
{
    public function __construct(
        public string $requestId,
        public string $requestDigest,
        public int $fromVersion,
        public int $toVersion,
        public string $registryChecksum,
        public string $authorizationDigest,
        public string $actor,
        public int $rollbackDeadline,
        public int $retentionDeadline,
        public ApplicationMasterRekeyState $state,
        public int $revision,
        public int $unresolvedFailures,
        public int $createdAt,
        public int $updatedAt,
    ) {}
}
