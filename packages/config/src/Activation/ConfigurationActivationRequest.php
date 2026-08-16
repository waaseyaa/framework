<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Immutable, replay-safe activation input. @api */
final class ConfigurationActivationRequest
{
    /** @var list<ConfigSyncFile> */
    private array $files;

    /** @var array<string, string> */
    private array $tombstones;

    /**
     * @param list<ConfigSyncFile> $files
     * @param array<string, string> $tombstones ref => expected active content hash
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ?ConfigurationActiveToken $expectedToken,
        array $files,
        array $tombstones = [],
        array $expectedEntryHashes = [],
        public readonly bool $completeReplacement = false,
        public readonly string $operation = 'activate',
        public readonly ?string $targetGenerationId = null,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Configuration activation request IDs must be stable printable identifiers.');
        }

        $byRef = [];
        foreach ($files as $file) {
            if (isset($byRef[$file->ref()])) {
                throw new \InvalidArgumentException('Configuration activation files must be unique ConfigSyncFile entries.');
            }
            $byRef[$file->ref()] = $file;
        }
        ksort($byRef, SORT_STRING);
        $this->files = array_values($byRef);

        ksort($tombstones, SORT_STRING);
        foreach ($tombstones as $ref => $hash) {
            if (isset($byRef[$ref]) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Configuration tombstones require a distinct ref and expected SHA-256 content hash.');
            }
        }
        $this->tombstones = $tombstones;

        ksort($expectedEntryHashes, SORT_STRING);
        foreach ($expectedEntryHashes as $ref => $hash) {
            if (!isset($byRef[$ref]) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Expected entry hashes must name submitted files and contain SHA-256 hashes.');
            }
        }
        $this->expectedEntryHashes = $expectedEntryHashes;

        if ($completeReplacement && $expectedToken === null) {
            throw new \InvalidArgumentException('Complete replacement requires an expected active token.');
        }
        if (!in_array($operation, ['activate', 'rollback'], true)) {
            throw new \InvalidArgumentException('Unknown configuration activation operation.');
        }
        if (($operation === 'rollback') !== ($targetGenerationId !== null)) {
            throw new \InvalidArgumentException('Rollback activation must bind exactly one target generation.');
        }
        if ($targetGenerationId !== null && preg_match('/^[a-f0-9]{64}$/D', $targetGenerationId) !== 1) {
            throw new \InvalidArgumentException('Rollback target generation must be a SHA-256 identity.');
        }
    }

    /** @return list<ConfigSyncFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, string> */
    public function tombstones(): array
    {
        return $this->tombstones;
    }

    public function inputHash(): string
    {
        $files = [];
        foreach ($this->files as $file) {
            $files[] = [
                'ref' => $file->ref(),
                'uuid' => $file->uuid,
                'dependencies' => $file->dependencies,
                'langcode' => $file->langcode,
                'fields' => $file->fields,
                'content_hash' => $file->contentHash(),
            ];
        }

        return hash('sha256', json_encode([
            'schema' => 'configuration-activation-input.v1',
            'expected' => $this->expectedToken === null ? null : [
                'generation_id' => $this->expectedToken->generationId,
                'activation_sequence' => $this->expectedToken->activationSequence,
            ],
            'files' => $files,
            'tombstones' => $this->tombstones,
            'expected_entry_hashes' => $this->expectedEntryHashes,
            'complete_replacement' => $this->completeReplacement,
            'operation' => $this->operation,
            'target_generation_id' => $this->targetGenerationId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @var array<string, string> */
    private array $expectedEntryHashes;

    /** @return array<string, string> */
    public function expectedEntryHashes(): array
    {
        return $this->expectedEntryHashes;
    }
}
