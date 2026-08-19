<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Immutable, replay-safe activation input. @api */
final class ConfigurationActivationRequest
{
    /** @var list<ConfigSyncFile> */
    private array $files;

    /** @var array<string, string> */
    private array $tombstones;

    /**
     * The one activation that may exist without CFG-03 verification (#2428).
     *
     * A site that has never been installed has no generation, and every
     * verified path needs one to already exist. This creates the canonical
     * EMPTY generation and nothing else — it carries no files, no manifest, and
     * no configurable payload, so there is nothing it could attest to. Every
     * subsequent change must go through the ordinary verified import path.
     *
     * @api
     */
    public static function genesis(string $requestId): self
    {
        return new self(
            requestId: $requestId,
            expectedToken: null,
            files: [],
            isGenesis: true,
        );
    }

    /**
     * @param array<string, string> $tombstones
     * @param array<string, string> $expectedEntryHashes
     */
    public static function activateVerified(
        string $requestId,
        ?ConfigurationActiveToken $expectedToken,
        VerifiedConfigBundle $bundle,
        array $tombstones = [],
        array $expectedEntryHashes = [],
    ): self {
        return new self(
            requestId: $requestId,
            expectedToken: $expectedToken,
            files: $bundle->files(),
            tombstones: $tombstones,
            expectedEntryHashes: $expectedEntryHashes,
            completeReplacement: true,
            verifiedBundle: $bundle,
        );
    }

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
        public readonly bool $isGenesis = false,
        public readonly ?string $targetGenerationId = null,
        public readonly ?VerifiedConfigBundle $verifiedBundle = null,
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

        if ($completeReplacement && $expectedToken === null && $verifiedBundle === null) {
            throw new \InvalidArgumentException('Complete replacement requires an expected active token.');
        }
        if (!in_array($operation, ['activate', 'rollback'], true)) {
            throw new \InvalidArgumentException('Unknown configuration activation operation.');
        }
        // Genesis (#2428) is truthfully an activation — it activates the
        // canonical EMPTY generation — so it keeps operation 'activate' rather
        // than inventing a verb. What it must never be able to do is carry
        // content, because it claims no CFG-03 verification: anything that
        // could express a payload is refused outright rather than ignored.
        if ($isGenesis
            && ($operation !== 'activate'
                || $this->files !== []
                || $tombstones !== []
                || $expectedEntryHashes !== []
                || $verifiedBundle !== null
                || $expectedToken !== null
                || $targetGenerationId !== null
                || $completeReplacement)
        ) {
            throw new \InvalidArgumentException(
                'Genesis activation accepts no files, tombstones, expectations, bundle, token, or target generation.',
            );
        }
        if (($operation === 'rollback') !== ($targetGenerationId !== null)) {
            throw new \InvalidArgumentException('Rollback activation must bind exactly one target generation.');
        }
        if ($targetGenerationId !== null && preg_match('/^[a-f0-9]{64}$/D', $targetGenerationId) !== 1) {
            throw new \InvalidArgumentException('Rollback target generation must be a SHA-256 identity.');
        }
        if ($operation === 'activate' && !$isGenesis) {
            if (!$verifiedBundle instanceof VerifiedConfigBundle) {
                throw new \InvalidArgumentException('Ordinary configuration activation requires a verified CFG-03 bundle.');
            }
            if (!$completeReplacement || self::fileBindings($this->files) !== self::fileBindings($verifiedBundle->files())) {
                throw new \InvalidArgumentException('Verified CFG-03 activation requires the exact complete bundle files.');
            }
        } elseif ($verifiedBundle !== null) {
            throw new \InvalidArgumentException('Retained-generation rollback must not replace immutable signature provenance.');
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
            'verified_manifest' => $this->verifiedBundle === null ? null : [
                'manifest_hash' => $this->verifiedBundle->verification->manifestHash,
                'bundle_scope' => $this->verifiedBundle->verification->bundleScope,
                'bundle_sequence' => $this->verifiedBundle->verification->bundleSequence,
                'trust_key_reference' => $this->verifiedBundle->verification->trustKeyReference,
                'signed' => $this->verifiedBundle->verification->signed,
                'effective_generation_id' => $this->verifiedBundle->effectiveManifest->generationId,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @var array<string, string> */
    private array $expectedEntryHashes;

    /** @return array<string, string> */
    public function expectedEntryHashes(): array
    {
        return $this->expectedEntryHashes;
    }

    /** @param list<ConfigSyncFile> $files @return array<string, array<string, mixed>> */
    private static function fileBindings(array $files): array
    {
        $bindings = [];
        foreach ($files as $file) {
            $bindings[$file->ref() . '@' . $file->langcode] = [
                'uuid' => $file->uuid,
                'dependencies' => $file->dependencies,
                'fields' => $file->fields,
                'schema_id' => $file->schemaId,
                'schema_version' => $file->schemaVersion,
                'schema_hash' => $file->schemaHash,
                'owner_package' => $file->ownerPackage,
                'owner_config_contract_version' => $file->ownerConfigContractVersion,
            ];
        }
        ksort($bindings, \SORT_STRING);

        return $bindings;
    }
}
