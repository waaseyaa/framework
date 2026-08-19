<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationContentionException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationRequestReuseException;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateMaintenanceInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateSweepAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationCandidateSweepRequest;
use Waaseyaa\Config\Activation\ConfigurationGenesisActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackValidatorInterface;
use Waaseyaa\Config\Activation\RefusingConfigurationCandidateSweepAuthorizer;
use Waaseyaa\Config\Activation\RefusingConfigurationRollbackValidator;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Database\DatabaseInterface;

/** Transactional DML authority for immutable configuration generations. */
final class DatabaseConfigurationActivator implements ConfigurationActivatorInterface, ConfigurationCandidateMaintenanceInterface, ConfigurationGenesisActivatorInterface
{
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ConfigurationAuthorityContext $context,
        private readonly ConfigurationActivationAuthorizerInterface $authorizer,
        private readonly ConfigurationRollbackValidatorInterface $rollbackValidator = new RefusingConfigurationRollbackValidator(),
        private readonly ConfigurationCandidateSweepAuthorizerInterface $sweepAuthorizer = new RefusingConfigurationCandidateSweepAuthorizer(),
    ) {}

    public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
    {
        try {
            return $this->activateTransactional($request);
        } catch (\Throwable $exception) {
            if ($exception instanceof ConfigurationActivationConflictException) {
                throw $exception;
            }
            if (
                $exception instanceof LockWaitTimeoutException
                || $exception instanceof DeadlockException
                || preg_match('/\b(?:database is locked|database table is locked|SQLITE_BUSY|SQLITE_LOCKED)\b/i', $exception->getMessage()) === 1
            ) {
                throw new ConfigurationActivationContentionException(
                    'Configuration activation could not acquire the writer authority before its timeout.',
                    previous: $exception,
                );
            }
            throw $exception;
        }
    }

    private function activateTransactional(ConfigurationActivationRequest $request): ConfigurationActivationResult
    {
        // Genesis (#2428) is not operator-authorized, and deliberately so.
        // It runs during installation, before any account exists, so there is
        // nobody to hold a permission; and it can express no content — the
        // request type refuses files, tombstones, expectations, bundles,
        // tokens, and target generations — so there is nothing to authorize
        // over. Its boundary is the restricted `install:init` lifecycle plus
        // the currentToken() === null precondition, both enforced above. Every
        // other operation still requires the bound authorizer.
        if (!$request->isGenesis) {
            ($this->authorizer)->authorize(
                $request,
                $request->tombstones() !== [] || $request->completeReplacement,
            );
        }
        $inputHash = $request->inputHash();
        $existing = $this->candidate($request->requestId);
        if ($existing !== null) {
            if ((string) $existing['input_hash'] !== $inputHash) {
                throw new ConfigurationActivationRequestReuseException(
                    'Configuration activation request ID was reused with different input or expected token.',
                );
            }
            if ((string) $existing['lifecycle_state'] === 'committed') {
                return new ConfigurationActivationResult(
                    'already-committed',
                    new ConfigurationActiveToken(
                        (string) $existing['generation_id'],
                        (int) $existing['committed_sequence'],
                    ),
                    $request->requestId,
                    (string) $existing['plan_hash'],
                    (string) $existing['input_hash'],
                    $this->expectedTokenFromCandidate($existing),
                    $request->requestId,
                );
            }
            if (!in_array((string) $existing['lifecycle_state'], ['staged', 'superseded'], true)) {
                throw new ConfigurationActivationRequestReuseException(sprintf(
                    'Configuration activation request ID is terminal in lifecycle state "%s"; retry with a new request ID.',
                    (string) $existing['lifecycle_state'],
                ));
            }
        }

        if (!$this->tokensEqual($request->expectedToken, $this->currentToken())) {
            throw new ConfigurationActivationConflictException('Configuration active token changed before planning.');
        }

        $activeEntries = $this->currentEntries();
        foreach ($request->expectedEntryHashes() as $ref => $expectedHash) {
            $active = $activeEntries[$ref] ?? null;
            if (!$active instanceof ConfigSyncFile || !hash_equals($active->contentHash(), $expectedHash)) {
                throw new ConfigurationActivationConflictException(
                    sprintf('Configuration update expectation for "%s" does not match the active entry.', $ref),
                );
            }
        }
        $nextEntries = $request->completeReplacement ? [] : $activeEntries;
        foreach ($request->files() as $file) {
            $nextEntries[$file->ref()] = $file;
        }
        foreach ($request->tombstones() as $ref => $expectedHash) {
            $active = $activeEntries[$ref] ?? null;
            if (!$active instanceof ConfigSyncFile || !hash_equals($active->contentHash(), $expectedHash)) {
                throw new ConfigurationActivationConflictException(
                    sprintf('Configuration tombstone for "%s" does not match one active entry.', $ref),
                );
            }
            unset($nextEntries[$ref]);
        }
        if ($request->completeReplacement) {
            $retainedRefs = array_fill_keys(array_map(
                static fn(ConfigSyncFile $file): string => $file->ref(),
                $request->files(),
            ), true);
            $requiredTombstones = array_diff_key($activeEntries, $retainedRefs);
            if (array_keys($requiredTombstones) !== array_keys($request->tombstones())) {
                throw new ConfigurationActivationConflictException(
                    'Complete replacement requires one content-bound tombstone for every omitted active entry.',
                );
            }
        }
        ksort($nextEntries, SORT_STRING);
        if ($request->isGenesis) {
            // Deterministic per site and content-free: the genesis generation
            // has no entries, so its identity can only be derived from the
            // authority it belongs to. A retry therefore resolves to the same
            // generation rather than a competing one.
            $generationId = self::genesisGenerationId($this->context);
            $manifestHash = $generationId;
        } elseif ($request->operation === 'activate') {
            $verifiedBundle = $request->verifiedBundle
                ?? throw new \LogicException('Verified activation bundle disappeared after request validation.');
            $generationId = $verifiedBundle->effectiveManifest->generationId;
            $manifestHash = $generationId;
        } else {
            $generationId = $request->targetGenerationId
                ?? throw new \LogicException('Rollback target generation disappeared after request validation.');
            $manifestHash = $this->manifestHashForGeneration($generationId);
        }
        $planHash = hash('sha256', json_encode([
            'schema' => 'configuration-activation-plan.v1',
            'input_hash' => $inputHash,
            'generation_id' => $generationId,
            'refs' => array_keys($nextEntries),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($existing === null) {
            $this->stage($request, $inputHash, $generationId, $manifestHash, $planHash, $nextEntries);
        } elseif ((string) $existing['generation_id'] !== $generationId || (string) $existing['plan_hash'] !== $planHash) {
            throw new ConfigurationActivationRequestReuseException('Staged activation request no longer resolves to its original plan.');
        } elseif ((string) $existing['lifecycle_state'] === 'superseded') {
            $this->restageSupersededCandidate($request->requestId, $inputHash, $generationId, $planHash);
        }

        $this->ensureInitialCounter();
        $previousCounter = $this->counter();
        $transaction = $this->database->transaction('configuration-activation');
        try {
            $affected = $this->database->update('waaseyaa_config_activation_counter')
                ->fields(['last_sequence' => $previousCounter + 1])
                ->condition('authority_id', $this->context->authorityId)
                ->condition('last_sequence', $previousCounter)
                ->execute();
            if ($affected !== 1) {
                throw new ConfigurationActivationConflictException('Configuration activation counter could not be claimed.');
            }
            $sequence = $previousCounter + 1;
            $current = $this->currentToken();
            if (!$this->tokensEqual($request->expectedToken, $current)) {
                throw new ConfigurationActivationConflictException('Configuration active token changed after planning.');
            }

            $this->database->query(
                'INSERT INTO waaseyaa_config_activation_v2 '
                . '(authority_id, activation_sequence, activation_request_id, generation_id, previous_generation_id, previous_activation_sequence, plan_hash, operation, target_generation_id, activated_at, is_genesis) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->context->authorityId,
                    $sequence,
                    $request->requestId,
                    $generationId,
                    $current?->generationId,
                    $current?->activationSequence,
                    $planHash,
                    $request->operation,
                    $request->targetGenerationId,
                    gmdate('c'),
                    $request->isGenesis ? 1 : 0,
                ],
            );
            // Genesis claims no CFG-03 verification, so there is no manifest
            // provenance to record. Every other activation still records it.
            if ($request->operation === 'activate' && !$request->isGenesis) {
                $this->recordVerifiedManifest($request, $generationId, $sequence);
            }
            $candidateUpdated = $this->database->update('waaseyaa_config_candidate')
                ->fields(['lifecycle_state' => 'committed', 'committed_sequence' => $sequence])
                ->condition('authority_id', $this->context->authorityId)
                ->condition('activation_request_id', $request->requestId)
                ->condition('lifecycle_state', 'staged')
                ->execute();
            if ($candidateUpdated !== 1) {
                throw new ConfigurationActivationConflictException('Configuration candidate was no longer staged at commit.');
            }
            $transaction->commit();
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
                // Preserve the initiating exception; the DB connection owns rollback health.
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw new ConfigurationActivationConflictException('Configuration activation identity conflicted at commit.', previous: $exception);
            }
            throw $exception;
        }

        return new ConfigurationActivationResult(
            'committed',
            new ConfigurationActiveToken($generationId, $sequence),
            $request->requestId,
            $planHash,
            $inputHash,
            $request->expectedToken,
            $request->requestId,
        );
    }

    public function activateGenesis(string $requestId): ConfigurationActivationResult
    {
        $active = $this->currentToken();
        if ($active !== null) {
            $committed = $this->committedResult($requestId);
            if ($committed !== null) {
                // This exact installation request already succeeded. Replay its
                // result so an interrupted install is safe to run again.
                return $committed;
            }

            throw new ConfigurationActivationConflictException(sprintf(
                'Configuration generation %s is already active; genesis must not create a competing generation.',
                $active->generationId,
            ));
        }

        return $this->activate(ConfigurationActivationRequest::genesis($requestId));
    }

    /** Content-free identity of the canonical empty generation for one authority. */
    public static function genesisGenerationId(ConfigurationAuthorityContext $context): string
    {
        return hash('sha256', 'configuration.genesis.empty.v1|' . $context->authorityId);
    }

    public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
    {
        if (!$this->tokenWasActivated($request->targetToken)) {
            throw new ConfigurationActivationConflictException('Configuration rollback target is not a retained activated generation.');
        }
        $targetFiles = array_values(iterator_to_array($this->readGeneration($request->targetToken)));
        ($this->rollbackValidator)->validate($request, $targetFiles);

        $targetRefs = array_fill_keys(array_map(
            static fn(ConfigSyncFile $file): string => $file->ref(),
            $targetFiles,
        ), true);
        $tombstones = [];
        foreach ($this->currentEntries() as $ref => $file) {
            if (!isset($targetRefs[$ref])) {
                $tombstones[$ref] = $file->contentHash();
            }
        }

        return $this->activate(new ConfigurationActivationRequest(
            $request->requestId,
            $request->expectedToken,
            $targetFiles,
            $tombstones,
            expectedEntryHashes: array_intersect_key(
                array_map(static fn(ConfigSyncFile $file): string => $file->contentHash(), $this->currentEntries()),
                $targetRefs,
            ),
            completeReplacement: true,
            operation: 'rollback',
            targetGenerationId: $request->targetToken->generationId,
            verifiedBundle: null,
        ));
    }

    public function committedResult(string $requestId): ?ConfigurationActivationResult
    {
        $candidate = $this->candidate($requestId);
        if ($candidate === null || (string) $candidate['lifecycle_state'] !== 'committed') {
            return null;
        }

        return new ConfigurationActivationResult(
            'already-committed',
            new ConfigurationActiveToken(
                (string) $candidate['generation_id'],
                (int) $candidate['committed_sequence'],
            ),
            $requestId,
            (string) $candidate['plan_hash'],
            (string) $candidate['input_hash'],
            $this->expectedTokenFromCandidate($candidate),
            $requestId,
        );
    }

    /** @param array<string, mixed> $candidate */
    private function expectedTokenFromCandidate(array $candidate): ?ConfigurationActiveToken
    {
        if ($candidate['expected_generation_id'] === null || $candidate['expected_activation_sequence'] === null) {
            return null;
        }

        return new ConfigurationActiveToken(
            (string) $candidate['expected_generation_id'],
            (int) $candidate['expected_activation_sequence'],
        );
    }

    public function supersedeStagedCandidates(ConfigurationCandidateSweepRequest $request): int
    {
        try {
            ($this->sweepAuthorizer)->authorize($request);
            $transaction = $this->database->transaction('configuration-candidate-sweep');
            try {
                $this->claimSweepFence($request);
                $affected = $this->database->update('waaseyaa_config_candidate')
                    ->fields(['lifecycle_state' => 'superseded'])
                    ->condition('authority_id', $this->context->authorityId)
                    ->condition('lifecycle_state', 'staged')
                    ->condition('created_at', $request->createdBefore->setTimezone(new \DateTimeZone('UTC'))->format('c'), '<')
                    ->execute();
                $transaction->commit();

                return $affected;
            } catch (\Throwable $exception) {
                try {
                    $transaction->rollBack();
                } catch (\Throwable) {
                }
                throw $exception;
            }
        } catch (\Throwable $exception) {
            if (
                $exception instanceof LockWaitTimeoutException
                || $exception instanceof DeadlockException
                || preg_match('/\b(?:database is locked|database table is locked|SQLITE_BUSY|SQLITE_LOCKED)\b/i', $exception->getMessage()) === 1
            ) {
                throw new ConfigurationActivationContentionException(
                    'Configuration candidate sweep could not acquire its writer authority before timeout.',
                    previous: $exception,
                );
            }
            throw $exception;
        }
    }

    private function claimSweepFence(ConfigurationCandidateSweepRequest $request): void
    {
        $affected = $this->database->update('waaseyaa_config_candidate_sweep_fence')
            ->fields(['last_fence' => $request->fence])
            ->condition('authority_id', $this->context->authorityId)
            ->condition('lease_domain', $request->leaseDomain)
            ->condition('last_fence', $request->fence, '<')
            ->execute();
        if ($affected === 1) {
            return;
        }
        foreach ($this->database->query(
            'SELECT last_fence FROM waaseyaa_config_candidate_sweep_fence WHERE authority_id = ? AND lease_domain = ?',
            [$this->context->authorityId, $request->leaseDomain],
        ) as $_row) {
            throw new ConfigurationActivationConflictException('Configuration candidate sweep fence is stale or replayed.');
        }
        try {
            $this->database->query(
                'INSERT INTO waaseyaa_config_candidate_sweep_fence (authority_id, lease_domain, last_fence) VALUES (?, ?, ?)',
                [$this->context->authorityId, $request->leaseDomain, $request->fence],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConfigurationActivationConflictException(
                'Configuration candidate sweep fence was concurrently initialized; retry with the same lease fence.',
                previous: $exception,
            );
        }
    }

    private function restageSupersededCandidate(
        string $requestId,
        string $inputHash,
        string $generationId,
        string $planHash,
    ): void {
        $affected = $this->database->update('waaseyaa_config_candidate')
            ->fields([
                'lifecycle_state' => 'staged',
                'created_at' => gmdate('c'),
            ])
            ->condition('authority_id', $this->context->authorityId)
            ->condition('activation_request_id', $requestId)
            ->condition('input_hash', $inputHash)
            ->condition('generation_id', $generationId)
            ->condition('plan_hash', $planHash)
            ->condition('lifecycle_state', 'superseded')
            ->execute();
        if ($affected !== 1) {
            throw new ConfigurationActivationConflictException(
                'Superseded configuration candidate changed before its exact retry could be re-staged.',
            );
        }
    }

    public function currentToken(): ?ConfigurationActiveToken
    {
        foreach ($this->database->query(
            'SELECT generation_id, activation_sequence FROM waaseyaa_config_activation_v2 '
            . 'WHERE authority_id = ? ORDER BY activation_sequence DESC LIMIT 1',
            [$this->context->authorityId],
        ) as $row) {
            return new ConfigurationActiveToken((string) $row['generation_id'], (int) $row['activation_sequence']);
        }

        if ($this->database->schema()->tableExists('waaseyaa_config_activation')) {
            foreach ($this->database->query(
                'SELECT generation_id, activation_sequence FROM waaseyaa_config_activation WHERE authority_id = ?',
                [$this->context->authorityId],
            ) as $row) {
                return new ConfigurationActiveToken((string) $row['generation_id'], (int) $row['activation_sequence']);
            }
        }

        return null;
    }

    /** @return iterable<ConfigSyncFile> */
    public function readGeneration(ConfigurationActiveToken $token): iterable
    {
        if ($this->generationExists($token->generationId)) {
            $table = 'waaseyaa_config_entry_v2';
        } elseif ($this->legacyGenerationExists($token->generationId)) {
            $table = 'waaseyaa_config_entry';
        } else {
            throw new ConfigurationActivationConflictException('Configuration generation content is absent.');
        }
        $hasContracts = $table === 'waaseyaa_config_entry_v2'
            && $this->database->schema()->tableExists('waaseyaa_config_entry_contract');
        $select = 'SELECT e.entity_type, e.entity_id, e.uuid, e.dependencies_json, e.langcode, e.fields_json';
        $join = '';
        if ($hasContracts) {
            $select .= ', c.format, c.schema_id, c.schema_version, c.schema_hash, c.owner_package, '
                . 'c.owner_config_contract_version, c.effective_entry_hash';
            $join = ' LEFT JOIN waaseyaa_config_entry_contract c ON c.authority_id = e.authority_id '
                . 'AND c.generation_id = e.generation_id AND c.config_name = e.config_name';
        }
        foreach ($this->database->query(
            $select . " FROM {$table} e" . $join
            . ' WHERE e.authority_id = ? AND e.generation_id = ? ORDER BY e.config_name',
            [$this->context->authorityId, $token->generationId],
        ) as $row) {
            $dependencies = json_decode((string) $row['dependencies_json'], true, flags: JSON_THROW_ON_ERROR);
            $fields = json_decode((string) $row['fields_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($dependencies) || !is_array($fields)) {
                throw new \UnexpectedValueException('Configuration generation contains invalid JSON.');
            }
            ksort($fields, SORT_STRING);
            $arguments = [
                'entityType' => (string) $row['entity_type'],
                'entityId' => (string) $row['entity_id'],
                'uuid' => (string) $row['uuid'],
                'dependencies' => array_values(array_filter($dependencies, 'is_string')),
                'langcode' => (string) $row['langcode'],
                'fields' => $fields,
            ];
            if (($row['format'] ?? null) === ConfigSyncFile::FORMAT_V1) {
                yield ConfigSyncFile::writable(
                    ...$arguments,
                    schemaId: (string) $row['schema_id'],
                    schemaVersion: (int) $row['schema_version'],
                    schemaHash: (string) $row['schema_hash'],
                    ownerPackage: (string) $row['owner_package'],
                    ownerConfigContractVersion: (int) $row['owner_config_contract_version'],
                );
            } else {
                yield ConfigSyncFile::legacyReadable(...$arguments);
            }
        }
    }

    /** @return array<string, ConfigSyncFile> */
    private function currentEntries(): array
    {
        $token = $this->currentToken();
        if ($token === null) {
            return [];
        }
        $entries = [];
        foreach ($this->readGeneration($token) as $file) {
            $entries[$file->ref()] = $file;
        }

        return $entries;
    }

    private function manifestHashForGeneration(string $generationId): string
    {
        foreach ($this->database->query(
            'SELECT manifest_hash FROM waaseyaa_config_generation_v2 WHERE authority_id = ? AND generation_id = ?',
            [$this->context->authorityId, $generationId],
        ) as $row) {
            return (string) $row['manifest_hash'];
        }

        throw new ConfigurationActivationConflictException('Retained rollback generation manifest is unavailable.');
    }

    private function recordVerifiedManifest(
        ConfigurationActivationRequest $request,
        string $generationId,
        int $activationSequence,
    ): void {
        $bundle = $request->verifiedBundle
            ?? throw new \LogicException('Verified activation evidence disappeared before commit.');
        $verification = $bundle->verification;
        $previousSequence = null;
        foreach ($this->database->query(
            'SELECT last_sequence FROM waaseyaa_config_manifest_replay '
            . 'WHERE authority_id = ? AND bundle_scope = ? AND trust_key_reference = ?',
            [$this->context->authorityId, $verification->bundleScope, $verification->trustKeyReference],
        ) as $row) {
            $previousSequence = (int) $row['last_sequence'];
        }
        if ($previousSequence !== null && $verification->bundleSequence <= $previousSequence) {
            throw new ConfigurationActivationConflictException(sprintf(
                'Configuration bundle sequence %d is not newer than committed sequence %d.',
                $verification->bundleSequence,
                $previousSequence,
            ));
        }
        if ($previousSequence === null) {
            $this->database->query(
                'INSERT INTO waaseyaa_config_manifest_replay '
                . '(authority_id, bundle_scope, trust_key_reference, last_sequence, manifest_hash, generation_id, activation_sequence) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->context->authorityId,
                    $verification->bundleScope,
                    $verification->trustKeyReference,
                    $verification->bundleSequence,
                    $verification->manifestHash,
                    $generationId,
                    $activationSequence,
                ],
            );
        } else {
            $affected = $this->database->update('waaseyaa_config_manifest_replay')
                ->fields([
                    'last_sequence' => $verification->bundleSequence,
                    'manifest_hash' => $verification->manifestHash,
                    'generation_id' => $generationId,
                    'activation_sequence' => $activationSequence,
                ])
                ->condition('authority_id', $this->context->authorityId)
                ->condition('bundle_scope', $verification->bundleScope)
                ->condition('trust_key_reference', $verification->trustKeyReference)
                ->condition('last_sequence', $previousSequence)
                ->execute();
            if ($affected !== 1) {
                throw new ConfigurationActivationConflictException('Configuration manifest replay state changed before commit.');
            }
        }

        $this->database->query(
            'INSERT INTO waaseyaa_config_activation_manifest '
            . '(authority_id, activation_sequence, generation_id, manifest_hash, manifest_bytes, registry_checksum, bundle_scope, bundle_sequence, trust_key_reference, signed) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->context->authorityId,
                $activationSequence,
                $generationId,
                $verification->manifestHash,
                $verification->manifest->canonicalBytes,
                (string) $verification->manifest->document['registry_checksum'],
                $verification->bundleScope,
                $verification->bundleSequence,
                $verification->trustKeyReference,
                $verification->signed ? 1 : 0,
            ],
        );
    }

    /** @param array<string, ConfigSyncFile> $entries */
    private function stage(
        ConfigurationActivationRequest $request,
        string $inputHash,
        string $generationId,
        string $manifestHash,
        string $planHash,
        array $entries,
        bool $allowConcurrentRetry = true,
    ): void {
        $now = gmdate('c');
        $transaction = $this->database->transaction('configuration-candidate-stage');
        try {
            if (!$this->generationExists($generationId)) {
                $this->database->query(
                    'INSERT INTO waaseyaa_config_generation_v2 (authority_id, generation_id, schema_version, manifest_hash, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?)',
                    [
                        $this->context->authorityId,
                        $generationId,
                        $request->operation === 'activate'
                            ? \Waaseyaa\Config\Manifest\EffectiveGenerationManifest::FORMAT_V1
                            : 'config-schema.v1',
                        $manifestHash,
                        $now,
                    ],
                );
                foreach ($entries as $file) {
                    $this->database->query(
                        'INSERT INTO waaseyaa_config_entry_v2 '
                        . '(authority_id, generation_id, config_name, entity_type, entity_id, uuid, dependencies_json, langcode, fields_json, content_hash) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $this->context->authorityId,
                            $generationId,
                            $file->ref(),
                            $file->entityType,
                            $file->entityId,
                            $file->uuid,
                            json_encode($file->dependencies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            $file->langcode,
                            json_encode($file->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            $file->contentHash(),
                        ],
                    );
                }
                // Genesis stages no entries and carries no bundle, so there is
                // no per-entry contract metadata to write.
                if ($request->operation === 'activate' && !$request->isGenesis) {
                    $bundle = $request->verifiedBundle
                        ?? throw new \LogicException('Verified entry metadata disappeared before staging.');
                    foreach ($bundle->entries() as $entry) {
                        $file = $entry->file;
                        $this->database->query(
                            'INSERT INTO waaseyaa_config_entry_contract '
                            . '(authority_id, generation_id, config_name, format, schema_id, schema_version, schema_hash, owner_package, owner_config_contract_version, effective_entry_hash) '
                            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            [
                                $this->context->authorityId,
                                $generationId,
                                $file->ref(),
                                ConfigSyncFile::FORMAT_V1,
                                $file->schemaId,
                                $file->schemaVersion,
                                $file->schemaHash,
                                $file->ownerPackage,
                                $file->ownerConfigContractVersion,
                                $entry->hashes->effectiveEntryHash,
                            ],
                        );
                    }
                }
            }
            $this->database->query(
                'INSERT INTO waaseyaa_config_candidate '
                . '(authority_id, activation_request_id, input_hash, expected_generation_id, expected_activation_sequence, generation_id, plan_hash, operation, target_generation_id, lifecycle_state, created_at, is_genesis) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->context->authorityId,
                    $request->requestId,
                    $inputHash,
                    $request->expectedToken?->generationId,
                    $request->expectedToken?->activationSequence,
                    $generationId,
                    $planHash,
                    $request->operation,
                    $request->targetGenerationId,
                    'staged',
                    $now,
                    $request->isGenesis ? 1 : 0,
                ],
            );
            $transaction->commit();
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                $candidate = $this->candidate($request->requestId);
                if ($candidate !== null) {
                    if (
                        hash_equals((string) $candidate['input_hash'], $inputHash)
                        && hash_equals((string) $candidate['generation_id'], $generationId)
                        && hash_equals((string) $candidate['plan_hash'], $planHash)
                        && (string) $candidate['lifecycle_state'] === 'staged'
                    ) {
                        return;
                    }
                    throw new ConfigurationActivationRequestReuseException(
                        'Concurrent staging found the request ID bound to a different or terminal candidate.',
                        previous: $exception,
                    );
                }
                if ($allowConcurrentRetry) {
                    $this->stage($request, $inputHash, $generationId, $manifestHash, $planHash, $entries, false);

                    return;
                }
                throw new ConfigurationActivationConflictException(
                    'Concurrent configuration generation staging did not converge.',
                    previous: $exception,
                );
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function candidate(string $requestId): ?array
    {
        foreach ($this->database->query(
            'SELECT input_hash, expected_generation_id, expected_activation_sequence, generation_id, plan_hash, lifecycle_state, committed_sequence '
            . 'FROM waaseyaa_config_candidate WHERE authority_id = ? AND activation_request_id = ?',
            [$this->context->authorityId, $requestId],
        ) as $row) {
            return $row;
        }

        return null;
    }

    private function ensureInitialCounter(): void
    {
        $currentToken = $this->currentToken();
        if ($currentToken !== null && $this->hasV2ActivationHistory()) {
            // Once an authority has history, deletion of its never-decreasing counter is corruption.
            $this->counter();

            return;
        }
        $initialSequence = $currentToken === null ? 0 : $currentToken->activationSequence;
        try {
            $this->database->query(
                'INSERT INTO waaseyaa_config_activation_counter (authority_id, last_sequence) VALUES (?, ?)',
                [$this->context->authorityId, $initialSequence],
            );
        } catch (UniqueConstraintViolationException) {
            // Existing never-deleted authority row.
        }
    }

    private function hasV2ActivationHistory(): bool
    {
        foreach ($this->database->query(
            'SELECT 1 FROM waaseyaa_config_activation_v2 WHERE authority_id = ? LIMIT 1',
            [$this->context->authorityId],
        ) as $_row) {
            return true;
        }

        return false;
    }

    private function generationExists(string $generationId): bool
    {
        foreach ($this->database->query(
            'SELECT 1 FROM waaseyaa_config_generation_v2 WHERE authority_id = ? AND generation_id = ?',
            [$this->context->authorityId, $generationId],
        ) as $_row) {
            return true;
        }

        return false;
    }

    private function legacyGenerationExists(string $generationId): bool
    {
        if (!$this->database->schema()->tableExists('waaseyaa_config_generation')) {
            return false;
        }
        foreach ($this->database->query(
            'SELECT 1 FROM waaseyaa_config_generation WHERE authority_id = ? AND generation_id = ? LIMIT 1',
            [$this->context->authorityId, $generationId],
        ) as $_row) {
            return true;
        }

        return false;
    }

    private function tokenWasActivated(ConfigurationActiveToken $token): bool
    {
        foreach ($this->database->query(
            'SELECT 1 FROM waaseyaa_config_activation_v2 '
            . 'WHERE authority_id = ? AND generation_id = ? AND activation_sequence = ?',
            [$this->context->authorityId, $token->generationId, $token->activationSequence],
        ) as $_row) {
            return true;
        }
        if ($this->database->schema()->tableExists('waaseyaa_config_activation')) {
            foreach ($this->database->query(
                'SELECT 1 FROM waaseyaa_config_activation '
                . 'WHERE authority_id = ? AND generation_id = ? AND activation_sequence = ?',
                [$this->context->authorityId, $token->generationId, $token->activationSequence],
            ) as $_row) {
                return true;
            }
        }

        return false;
    }

    private function counter(): int
    {
        foreach ($this->database->query(
            'SELECT last_sequence FROM waaseyaa_config_activation_counter WHERE authority_id = ?',
            [$this->context->authorityId],
        ) as $row) {
            return (int) $row['last_sequence'];
        }
        throw new ConfigurationActivationConflictException('Configuration activation counter is missing.');
    }

    private function tokensEqual(?ConfigurationActiveToken $expected, ?ConfigurationActiveToken $actual): bool
    {
        return $expected === null
            ? $actual === null
            : $actual !== null
                && $expected->activationSequence === $actual->activationSequence
                && hash_equals($expected->generationId, $actual->generationId);
    }
}
