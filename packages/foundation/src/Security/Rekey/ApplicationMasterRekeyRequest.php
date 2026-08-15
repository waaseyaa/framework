<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** Immutable non-secret request tuple authorized by the consuming application. @api */
final readonly class ApplicationMasterRekeyRequest
{
    public function __construct(
        public string $requestId,
        public int $fromVersion,
        public int $toVersion,
        public string $registryChecksum,
        public string $authorizationDigest,
        public string $actor,
        public int $rollbackDeadline,
        public int $retentionDeadline,
        public int $createdAt,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $requestId)) {
            throw new \InvalidArgumentException('Application-master rekey request IDs must be stable lowercase identifiers.');
        }
        if ($fromVersion < 1 || $toVersion <= $fromVersion) {
            throw new \InvalidArgumentException('Application-master rekey versions must advance monotonically.');
        }
        self::assertHash($registryChecksum, 'registry checksum');
        self::assertHash($authorizationDigest, 'authorization digest');
        if (!preg_match('/^[a-z0-9][a-z0-9._:@-]{0,127}$/D', $actor)) {
            throw new \InvalidArgumentException('Application-master rekey actors must be stable non-secret identifiers.');
        }
        if ($createdAt < 0 || $rollbackDeadline <= $createdAt) {
            throw new \InvalidArgumentException('Application-master rekey rollback deadlines must follow request creation.');
        }
        if ($retentionDeadline < $rollbackDeadline) {
            throw new \InvalidArgumentException('Application-master retention must cover the rollback and backup/key horizon.');
        }
    }

    public function digest(): string
    {
        return hash('sha256', json_encode([
            'format' => 'waaseyaa.application-master.rekey-request.v1',
            'request_id' => $this->requestId,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'registry_checksum' => $this->registryChecksum,
            'authorization_digest' => $this->authorizationDigest,
            'actor' => $this->actor,
            'rollback_deadline' => $this->rollbackDeadline,
            'retention_deadline' => $this->retentionDeadline,
            'created_at' => $this->createdAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function assertHash(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Application-master rekey %s must be a lowercase SHA-256 digest.', $label));
        }
    }
}
