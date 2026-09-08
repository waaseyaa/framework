<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\SiteContract\CanonicalJson;

/** A closed transport document for one pre-authorized fresh-project bundle. @api */
final readonly class ProjectConfigAuthorization
{
    public const string SCHEMA_ID = 'waaseyaa.project_config_authorization';
    public const int CONTRACT_VERSION = 1;
    public const string PRODUCER = 'project_config_authorizer_v1';

    private function __construct(
        public string $siteManifestDigest,
        public string $sitePlanDigest,
        public SignedConfigManifestEnvelope $envelope,
        public ConfigSyncBundleManifest $bundleManifest,
    ) {}

    public static function issue(
        string $siteManifestDigest,
        string $sitePlanDigest,
        SignedConfigManifestEnvelope $envelope,
    ): self {
        return self::fromArray([
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'site_manifest_digest' => $siteManifestDigest,
            'site_plan_digest' => $sitePlanDigest,
            'envelope' => $envelope->toArray(),
        ]);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        self::assertExactKeys($document, ['envelope', 'schema', 'site_manifest_digest', 'site_plan_digest', 'version']);
        if (($document['schema'] ?? null) !== self::SCHEMA_ID || ($document['version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new \InvalidArgumentException('Project configuration authorization declares an unsupported contract.');
        }
        $manifestDigest = self::digest($document['site_manifest_digest'] ?? null, 'site_manifest_digest');
        $planDigest = self::digest($document['site_plan_digest'] ?? null, 'site_plan_digest');
        if (!\is_array($document['envelope']) || array_is_list($document['envelope'])) {
            throw new \InvalidArgumentException('Project configuration authorization envelope must be an object.');
        }

        /** @var array<string, mixed> $envelopeDocument */
        $envelopeDocument = $document['envelope'];
        $envelope = SignedConfigManifestEnvelope::fromArray($envelopeDocument);
        $manifest = ConfigSyncBundleManifest::fromCanonicalBytes($envelope->manifestBytes);
        $scope = self::scope($manifestDigest, $planDigest);
        if (($envelope->protectedHeader['bundle_scope'] ?? null) !== $scope
            || ($envelope->protectedHeader['bundle_sequence'] ?? null) !== 1
            || ($manifest->document['bundle_scope'] ?? null) !== $scope
            || ($manifest->document['bundle_sequence'] ?? null) !== 1
        ) {
            throw new \InvalidArgumentException('Project configuration authorization is not the initial bundle for its evaluated site identity.');
        }
        if (($manifest->document['producer_evidence'] ?? null) !== self::producerEvidence($manifestDigest, $planDigest)) {
            throw new \InvalidArgumentException('Project configuration authorization does not match its signed producer evidence.');
        }

        return new self($manifestDigest, $planDigest, $envelope, $manifest);
    }

    public static function fromJson(string $bytes): self
    {
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Project configuration authorization is not valid JSON.', previous: $exception);
        }
        if (!\is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('Project configuration authorization must be a JSON object.');
        }

        /** @var array<string, mixed> $document */
        $authorization = self::fromArray($document);
        if (trim($bytes) !== $authorization->canonicalJson()) {
            throw new \InvalidArgumentException('Project configuration authorization must use canonical JSON bytes.');
        }

        return $authorization;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'site_manifest_digest' => $this->siteManifestDigest,
            'site_plan_digest' => $this->sitePlanDigest,
            'envelope' => $this->envelope->toArray(),
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function assertMatches(string $siteManifestDigest, string $sitePlanDigest): void
    {
        if (!hash_equals($this->siteManifestDigest, $siteManifestDigest)
            || !hash_equals($this->sitePlanDigest, $sitePlanDigest)
        ) {
            throw new \InvalidArgumentException('Project configuration authorization does not match the evaluated site manifest and plan.');
        }
    }

    public static function scope(string $siteManifestDigest, string $sitePlanDigest): string
    {
        self::digest($siteManifestDigest, 'site_manifest_digest');
        self::digest($sitePlanDigest, 'site_plan_digest');

        return 'project-init/' . $siteManifestDigest . '/' . $sitePlanDigest;
    }

    /** @return array<string, string> */
    public static function producerEvidence(string $siteManifestDigest, string $sitePlanDigest): array
    {
        return [
            'producer' => self::PRODUCER,
            'site_manifest_digest' => self::digest($siteManifestDigest, 'site_manifest_digest'),
            'site_plan_digest' => self::digest($sitePlanDigest, 'site_plan_digest'),
        ];
    }

    private static function digest(mixed $value, string $field): string
    {
        if (!\is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Project configuration authorization %s must be a lowercase SHA-256 digest.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $document @param list<string> $expected */
    private static function assertExactKeys(array $document, array $expected): void
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Project configuration authorization contains missing or unknown fields.');
        }
    }
}
