<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Computes non-interchangeable exact, authored, and effective identities. @api */
final class ConfigContentHasher
{
    public const EXACT_BYTES_DOMAIN = 'waaseyaa.config-sync-exact-bytes/1';
    public const AUTHORED_DOMAIN = 'waaseyaa.config-authored/1';
    public const EFFECTIVE_DOMAIN = 'waaseyaa.config-effective/1';

    public function __construct(
        private readonly CanonicalConfigEncoder $encoder = new CanonicalConfigEncoder(),
        private readonly ConfigSchemaValidator $validator = new ConfigSchemaValidator(),
    ) {}

    public function hash(ConfigSyncFile $file, string $exactYamlBytes, ConfigSchemaRegistry $registry): ConfigContentHashes
    {
        if (!$file->isWritableV1()) {
            throw new \InvalidArgumentException('Only writable v1 configuration has authored and effective CFG-03 identities.');
        }
        if (!$registry->isFrozen()) {
            throw new \LogicException('Configuration content identity requires the frozen schema registry.');
        }
        if (preg_match('//u', $exactYamlBytes) !== 1) {
            throw new \InvalidArgumentException('Exact sync bytes must be valid UTF-8.');
        }

        $registration = $registry->get($file->schemaId, $file->schemaVersion)
            ?? throw new \InvalidArgumentException(sprintf(
                'No frozen schema registration matches %s version %d.',
                $file->schemaId,
                $file->schemaVersion,
            ));
        $this->assertBoundIdentity($file, $registration);

        $violations = $this->validator->validate($file->fields, $registration->schema);
        if ($violations !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Authored configuration %s fails schema validation at %s: %s',
                $file->ref(),
                $violations[0]->path,
                $violations[0]->message,
            ));
        }
        $semanticViolations = $registry->semanticViolations($file->schemaId, $file->schemaVersion, $file->fields);
        if ($semanticViolations !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Authored configuration %s fails semantic validation at %s: %s',
                $file->ref(),
                $semanticViolations[0]->path,
                $semanticViolations[0]->message,
            ));
        }
        $effective = $this->validator->materialize($file->fields, $registration->schema);
        $dependencies = $file->dependencies;
        sort($dependencies, \SORT_STRING);
        $identity = [
            'dependencies' => $dependencies,
            'entity_id' => $file->entityId,
            'entity_type' => $file->entityType,
            'langcode' => $file->langcode,
            'owner_config_contract_version' => $registration->ownerConfigContractVersion,
            'owner_package' => $registration->ownerPackage,
            'schema_hash' => $registration->canonicalSchemaHash,
            'schema_id' => $registration->schemaId,
            'schema_version' => $registration->schemaVersion,
            'uuid' => $file->uuid,
        ];

        return new ConfigContentHashes(
            exactByteHash: $this->encoder->digestBytes(self::EXACT_BYTES_DOMAIN, $exactYamlBytes),
            authoredContentHash: $this->encoder->digest(self::AUTHORED_DOMAIN, [
                ...$identity,
                'canonical_fields' => $this->encoder->encode($file->fields, $registration->schema),
            ]),
            effectiveEntryHash: $this->encoder->digest(self::EFFECTIVE_DOMAIN, [
                ...$identity,
                'canonical_fields' => $this->encoder->encode($effective, $registration->schema),
            ]),
            effectiveFields: $effective,
        );
    }

    private function assertBoundIdentity(ConfigSyncFile $file, ConfigSchemaRegistration $registration): void
    {
        $actual = [
            $file->schemaHash,
            $file->ownerPackage,
            $file->ownerConfigContractVersion,
        ];
        $expected = [
            $registration->canonicalSchemaHash,
            $registration->ownerPackage,
            $registration->ownerConfigContractVersion,
        ];
        if ($actual !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Configuration %s schema or package identity does not match the frozen registry.',
                $file->ref(),
            ));
        }
    }
}
