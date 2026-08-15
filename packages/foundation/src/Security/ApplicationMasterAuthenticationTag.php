<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Versioned, purpose-bound non-secret authentication output. @api */
final readonly class ApplicationMasterAuthenticationTag
{
    public const string FORMAT = 'waaseyaa.application-master.authentication.v1';

    private const array FIELDS = ['format', 'master_version', 'purpose', 'digest'];

    private function __construct(
        public int $masterVersion,
        public string $purpose,
        public string $digest,
    ) {}

    /** @internal Cryptographic operation factory. */
    public static function seal(int $masterVersion, string $purpose, string $digestBytes): self
    {
        if ($masterVersion < 1) {
            throw new \InvalidArgumentException('Application-master authentication versions must be positive.');
        }
        self::assertPurpose($purpose);
        if (strlen($digestBytes) !== 32) {
            throw new \InvalidArgumentException('Application-master authentication digests must be exactly 32 bytes.');
        }

        return new self($masterVersion, $purpose, base64_encode($digestBytes));
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        $expected = self::FIELDS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Application-master authentication tag fields are not exact.');
        }
        if ($document['format'] !== self::FORMAT
            || !is_int($document['master_version'])
            || !is_string($document['purpose'])
            || !is_string($document['digest'])) {
            throw new \InvalidArgumentException('Application-master authentication tags require exact fields and types.');
        }
        $digest = base64_decode($document['digest'], true);
        if ($digest === false || base64_encode($digest) !== $document['digest']) {
            throw new \InvalidArgumentException('Application-master authentication tags require canonical base64 digests.');
        }

        return self::seal($document['master_version'], $document['purpose'], $digest);
    }

    /** @return array{format: string, master_version: int, purpose: string, digest: string} */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'master_version' => $this->masterVersion,
            'purpose' => $this->purpose,
            'digest' => $this->digest,
        ];
    }

    public function digestBytes(): string
    {
        $digest = base64_decode($this->digest, true);
        if ($digest === false) {
            throw new \LogicException('Application-master authentication tag custody is invalid.');
        }

        return $digest;
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $purpose)) {
            throw new \InvalidArgumentException('Application-master authentication purposes must be versioned Waaseyaa identifiers.');
        }
    }
}
