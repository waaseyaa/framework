<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

/** One immutable hash-chained non-secret rekey evidence event. @api */
final readonly class ApplicationMasterRekeyEvent
{
    public const string FORMAT = 'waaseyaa.application-master.rekey-event.v1';

    public function __construct(
        public string $requestId,
        public int $sequence,
        public string $eventType,
        public string $bodyJson,
        public string $previousHash,
        public string $eventHash,
        public int $createdAt,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D', $requestId)
            || $sequence < 1
            || !preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $eventType)
            || $createdAt < 0) {
            throw new \InvalidArgumentException('Application-master rekey event identity metadata is invalid.');
        }
        foreach ([$previousHash, $eventHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Application-master rekey event hashes must be lowercase SHA-256 digests.');
            }
        }
        json_decode($bodyJson, true, 32, JSON_THROW_ON_ERROR);
    }

    public function recomputeHash(): string
    {
        return self::computeHash(
            $this->requestId,
            $this->sequence,
            $this->eventType,
            $this->bodyJson,
            $this->previousHash,
            $this->createdAt,
        );
    }

    public static function computeHash(
        string $requestId,
        int $sequence,
        string $eventType,
        string $bodyJson,
        string $previousHash,
        int $createdAt,
    ): string {
        return hash('sha256', implode("\0", [
            self::FORMAT,
            $requestId,
            (string) $sequence,
            $eventType,
            $bodyJson,
            $previousHash,
            (string) $createdAt,
        ]));
    }
}
