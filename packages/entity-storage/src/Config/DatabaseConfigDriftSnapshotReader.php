<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Drift\ConfigDriftSnapshot;
use Waaseyaa\Config\Drift\ConfigDriftSnapshotReaderInterface;
use Waaseyaa\Database\DatabaseInterface;

/** One-transaction SQLite implementation of the CFG-03 drift snapshot seam. */
final readonly class DatabaseConfigDriftSnapshotReader implements ConfigDriftSnapshotReaderInterface
{
    public function __construct(
        private DatabaseInterface $database,
        private ConfigurationAuthorityContext $context,
        private ActiveConfigurationBridgeInterface $bridge,
    ) {}

    public function readSnapshot(): ConfigDriftSnapshot
    {
        $token = new ConfigurationActiveToken(
            $this->context->requireActiveGenerationId(),
            $this->context->activationSequence
                ?? throw new \LogicException('Active configuration sequence is unavailable.'),
        );
        $transaction = $this->database->transaction('configuration-drift-snapshot');
        try {
            $this->assertActiveToken($token);
            $generation = $this->one(
                'SELECT schema_version, manifest_hash FROM waaseyaa_config_generation_v2 '
                . 'WHERE authority_id = ? AND generation_id = ?',
                [$this->context->authorityId, $token->generationId],
                'Active CFG-03 generation row is unavailable.',
            );
            $manifest = $this->one(
                'SELECT activation_sequence, manifest_hash, manifest_bytes, bundle_sequence, trust_key_reference '
                . 'FROM waaseyaa_config_activation_manifest '
                . 'WHERE authority_id = ? AND generation_id = ? AND activation_sequence <= ? '
                . 'ORDER BY activation_sequence DESC LIMIT 1',
                [$this->context->authorityId, $token->generationId, $token->activationSequence],
                'Active generation has no retained CFG-03 manifest provenance.',
            );
            $replay = $this->one(
                'SELECT last_sequence, manifest_hash, generation_id, activation_sequence FROM waaseyaa_config_manifest_replay '
                . 'WHERE authority_id = ? AND bundle_scope = ? AND trust_key_reference = ?',
                [
                    $this->context->authorityId,
                    $this->manifestScope((string) $manifest['manifest_bytes']),
                    (string) $manifest['trust_key_reference'],
                ],
                'Active manifest replay state is unavailable.',
            );
            $replayEvidence = null;
            foreach ($this->database->query(
                'SELECT generation_id, manifest_hash, bundle_scope, bundle_sequence, trust_key_reference '
                . 'FROM waaseyaa_config_activation_manifest WHERE authority_id = ? AND activation_sequence = ?',
                [$this->context->authorityId, (int) $replay['activation_sequence']],
            ) as $row) {
                $replayEvidence = $row;
                break;
            }
            $replayEvidenceConsistent = \is_array($replayEvidence)
                && (string) $replayEvidence['generation_id'] === (string) $replay['generation_id']
                && (string) $replayEvidence['manifest_hash'] === (string) $replay['manifest_hash']
                && (string) $replayEvidence['bundle_scope'] === $this->manifestScope((string) $manifest['manifest_bytes'])
                && (int) $replayEvidence['bundle_sequence'] === (int) $replay['last_sequence']
                && (string) $replayEvidence['trust_key_reference'] === (string) $manifest['trust_key_reference'];
            $files = array_values(iterator_to_array($this->bridge->iterate()));
            $activeEntryHashes = $this->activeEntryHashes($token->generationId);
            $transaction->commit();

            return new ConfigDriftSnapshot(
                activeToken: $token,
                files: $files,
                activeEntryHashes: $activeEntryHashes,
                generationSchemaVersion: (string) $generation['schema_version'],
                generationManifestHash: (string) $generation['manifest_hash'],
                authoredManifestHash: (string) $manifest['manifest_hash'],
                authoredManifestBytes: (string) $manifest['manifest_bytes'],
                provenanceActivationSequence: (int) $manifest['activation_sequence'],
                manifestBundleSequence: (int) $manifest['bundle_sequence'],
                manifestTrustKeyReference: (string) $manifest['trust_key_reference'],
                replayEvidenceConsistent: $replayEvidenceConsistent,
                replayLastSequence: (int) $replay['last_sequence'],
            );
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    private function assertActiveToken(ConfigurationActiveToken $token): void
    {
        $row = $this->one(
            'SELECT generation_id, activation_sequence FROM waaseyaa_config_activation_v2 '
            . 'WHERE authority_id = ? ORDER BY activation_sequence DESC LIMIT 1',
            [$this->context->authorityId],
            'CFG-02 active activation row is unavailable.',
        );
        if ((string) $row['generation_id'] !== $token->generationId
            || (int) $row['activation_sequence'] !== $token->activationSequence
        ) {
            throw new \RuntimeException('Active configuration token changed outside the pinned drift snapshot.');
        }
    }

    private function manifestScope(string $bytes): string
    {
        $document = json_decode($bytes, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($document) || !\is_string($document['bundle_scope'] ?? null)) {
            throw new \UnexpectedValueException('Stored CFG-03 manifest scope is malformed.');
        }

        return $document['bundle_scope'];
    }

    /** @return array<string, string> */
    private function activeEntryHashes(string $generationId): array
    {
        $hashes = [];
        foreach ($this->database->query(
            'SELECT e.config_name, e.langcode, c.effective_entry_hash '
            . 'FROM waaseyaa_config_entry_v2 e LEFT JOIN waaseyaa_config_entry_contract c '
            . 'ON c.authority_id = e.authority_id AND c.generation_id = e.generation_id AND c.config_name = e.config_name '
            . 'WHERE e.authority_id = ? AND e.generation_id = ? ORDER BY e.config_name, e.langcode',
            [$this->context->authorityId, $generationId],
        ) as $row) {
            $hashes[(string) $row['config_name'] . '@' . (string) $row['langcode']] = (string) ($row['effective_entry_hash'] ?? '');
        }
        ksort($hashes, \SORT_STRING);

        return $hashes;
    }

    /** @param list<mixed> $arguments @return array<string, mixed> */
    private function one(string $sql, array $arguments, string $missingMessage): array
    {
        foreach ($this->database->query($sql, $arguments) as $row) {
            return $row;
        }

        throw new \RuntimeException($missingMessage);
    }
}
