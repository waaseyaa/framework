<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Strict version-bound authenticated ciphertext envelope. @api */
final readonly class ApplicationMasterEnvelope
{
    public const string FORMAT = 'waaseyaa.application-master.envelope.v1';

    private const array FIELDS = [
        'format',
        'master_version',
        'purpose',
        'record_identity',
        'schema_version',
        'nonce',
        'ciphertext',
    ];

    private function __construct(
        public int $masterVersion,
        public string $purpose,
        public string $recordIdentity,
        public int $schemaVersion,
        private string $nonce,
        private string $ciphertext,
    ) {}

    /** @internal Cryptographic operation factory. */
    public static function seal(
        int $masterVersion,
        string $purpose,
        string $recordIdentity,
        int $schemaVersion,
        string $nonce,
        string $ciphertext,
    ): self {
        self::assertMetadata($masterVersion, $purpose, $recordIdentity, $schemaVersion);
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new \InvalidArgumentException('Application-master envelope nonce length is invalid.');
        }
        if (strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \InvalidArgumentException('Application-master envelope ciphertext is truncated.');
        }

        return new self($masterVersion, $purpose, $recordIdentity, $schemaVersion, $nonce, $ciphertext);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        $expected = self::FIELDS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Application-master envelope fields are not exact.');
        }
        if ($document['format'] !== self::FORMAT
            || !is_int($document['master_version'])
            || !is_string($document['purpose'])
            || !is_string($document['record_identity'])
            || !is_int($document['schema_version'])
            || !is_string($document['nonce'])
            || !is_string($document['ciphertext'])) {
            throw new \InvalidArgumentException('Application-master envelope field types are invalid.');
        }

        return self::seal(
            $document['master_version'],
            $document['purpose'],
            $document['record_identity'],
            $document['schema_version'],
            self::decodeCanonicalBase64($document['nonce'], 'nonce'),
            self::decodeCanonicalBase64($document['ciphertext'], 'ciphertext'),
        );
    }

    /** @return array{format: string, master_version: int, purpose: string, record_identity: string, schema_version: int, nonce: string, ciphertext: string} */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'master_version' => $this->masterVersion,
            'purpose' => $this->purpose,
            'record_identity' => $this->recordIdentity,
            'schema_version' => $this->schemaVersion,
            'nonce' => base64_encode($this->nonce),
            'ciphertext' => base64_encode($this->ciphertext),
        ];
    }

    /** @internal */
    public function nonceBytes(): string
    {
        return $this->nonce;
    }

    /** @internal */
    public function ciphertextBytes(): string
    {
        return $this->ciphertext;
    }

    /** @internal */
    public function associatedData(): string
    {
        return self::encodeAssociatedData(
            $this->masterVersion,
            $this->purpose,
            $this->recordIdentity,
            $this->schemaVersion,
        );
    }

    /** @internal */
    public static function encodeAssociatedData(
        int $masterVersion,
        string $purpose,
        string $recordIdentity,
        int $schemaVersion,
    ): string {
        self::assertMetadata($masterVersion, $purpose, $recordIdentity, $schemaVersion);

        return json_encode([
            'format' => self::FORMAT,
            'master_version' => $masterVersion,
            'purpose' => $purpose,
            'record_identity' => $recordIdentity,
            'schema_version' => $schemaVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function assertMetadata(
        int $masterVersion,
        string $purpose,
        string $recordIdentity,
        int $schemaVersion,
    ): void {
        if ($masterVersion < 1) {
            throw new \InvalidArgumentException('Application-master envelope versions must be positive.');
        }
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $purpose)) {
            throw new \InvalidArgumentException('Application-master envelope purposes must be versioned Waaseyaa identifiers.');
        }
        if ($recordIdentity === ''
            || strlen($recordIdentity) > 512
            || preg_match('//u', $recordIdentity) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $recordIdentity)) {
            throw new \InvalidArgumentException('Application-master envelope record identities must be bounded printable values.');
        }
        if ($schemaVersion < 1) {
            throw new \InvalidArgumentException('Application-master envelope schema versions must be positive.');
        }
    }

    private static function decodeCanonicalBase64(string $encoded, string $field): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new \InvalidArgumentException(sprintf('Application-master envelope %s must use canonical base64.', $field));
        }

        return $decoded;
    }
}
