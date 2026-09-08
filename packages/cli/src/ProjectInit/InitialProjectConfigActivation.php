<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationGenesisIdentity;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Dependency\DependencyResolver;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\InitialConfigImportPreflightInterface;

/** Fresh-only signed activation coordinator with exact committed replay reconciliation. @api */
final readonly class InitialProjectConfigActivation
{
    public function __construct(
        private ConfigSyncRepository $repository,
        private InitialConfigImportPreflightInterface $preflight,
        private ConfigurationActivatorInterface $activator,
        private ConfigurationAuthorityContext $authority,
        private DependencyResolver $dependencies = new DependencyResolver(),
    ) {}

    public function activate(
        ProjectConfigAuthorization $authorization,
        string $siteManifestDigest,
        string $sitePlanDigest,
    ): InitialProjectConfigActivationResult {
        $authorization->assertMatches($siteManifestDigest, $sitePlanDigest);
        $requestId = self::requestId($this->authority, $authorization);
        $syncFiles = $this->syncFiles();
        $committed = $this->activator->committedResult($requestId);

        if ($committed instanceof ConfigurationActivationResult) {
            return $this->reconcileCommitted($authorization, $committed, $syncFiles);
        }

        $genesis = ConfigurationGenesisIdentity::token($this->authority);
        $current = $this->activator->currentToken();
        if (!$this->sameToken($current, $genesis)) {
            throw new InitialProjectConfigActivationException(
                'Initial project configuration activation requires the exact canonical genesis generation; existing-application migration is outside this fresh-project command.',
            );
        }
        $activeFiles = iterator_to_array($this->activator->readGeneration($genesis));
        if ($activeFiles !== []) {
            throw new InitialProjectConfigActivationException('The canonical genesis generation is not empty; refusing initial project activation.');
        }

        $verified = $this->preflight->assertReadyFromEnvelope(
            $authorization->envelope,
            $syncFiles,
            [],
        );
        $this->assertBundleMatchesAuthorization($verified, $authorization);
        $this->assertDependencies($verified);
        $request = ConfigurationActivationRequest::activateVerified($requestId, $genesis, $verified);

        try {
            $result = $this->activator->activate($request);
        } catch (\Throwable $exception) {
            $committed = $this->activator->committedResult($requestId);
            if (!$committed instanceof ConfigurationActivationResult) {
                throw new InitialProjectConfigActivationException(
                    'Initial project configuration activation outcome is uncertain; retry the same project:init request for read-only reconciliation.',
                    true,
                    $exception,
                );
            }

            return $this->reconcileCommitted($authorization, $committed, $syncFiles);
        }

        $this->assertCommittedIdentity($result, $verified);

        return new InitialProjectConfigActivationResult(
            $result->status === 'already-committed' ? 'already_completed' : 'completed',
            $requestId,
            $result->token,
            $verified->verification->manifestHash,
        );
    }

    public static function requestId(
        ConfigurationAuthorityContext $authority,
        ProjectConfigAuthorization $authorization,
    ): string {
        return 'project-config-init-' . substr(hash(
            'sha256',
            'configuration.project.initial.v1|' . $authority->authorityId . '|' . $authorization->bundleManifest->manifestHash,
        ), 0, 32);
    }

    /** @param array<string, ConfigSyncFile> $syncFiles */
    private function reconcileCommitted(
        ProjectConfigAuthorization $authorization,
        ConfigurationActivationResult $committed,
        array $syncFiles,
    ): InitialProjectConfigActivationResult {
        if ($committed->requestId !== self::requestId($this->authority, $authorization)) {
            throw new InitialProjectConfigActivationException('Committed activation result has a different request identity.');
        }
        $verified = $this->preflight->assertCommittedReplayReadyFromEnvelope(
            $authorization->envelope,
            (int) $authorization->envelope->protectedHeader['bundle_sequence'],
            $syncFiles,
            array_map(static fn(ConfigSyncFile $file): string => $file->ref(), iterator_to_array($this->activator->readGeneration($committed->token))),
        );
        $this->assertBundleMatchesAuthorization($verified, $authorization);
        $this->assertDependencies($verified);
        $this->assertCommittedIdentity($committed, $verified);

        return new InitialProjectConfigActivationResult(
            'already_completed',
            $committed->requestId,
            $committed->token,
            $verified->verification->manifestHash,
        );
    }

    private function assertCommittedIdentity(ConfigurationActivationResult $committed, VerifiedConfigBundle $verified): void
    {
        $current = $this->activator->currentToken();
        if (!$this->sameToken($current, $committed->token)
            || !hash_equals($verified->effectiveManifest->generationId, $committed->token->generationId)
        ) {
            throw new InitialProjectConfigActivationException(
                'Committed project configuration does not match the current activation identity; refusing to claim completion.',
            );
        }
        $genesis = ConfigurationGenesisIdentity::token($this->authority);
        if (!$this->sameToken($committed->originalExpectedToken, $genesis)) {
            throw new InitialProjectConfigActivationException(
                'Committed project configuration was not activated from the canonical genesis generation.',
            );
        }
    }

    private function assertBundleMatchesAuthorization(
        VerifiedConfigBundle $verified,
        ProjectConfigAuthorization $authorization,
    ): void {
        if (!hash_equals($authorization->bundleManifest->manifestHash, $verified->verification->manifestHash)
            || $verified->verification->bundleSequence !== 1
            || !$verified->verification->signed
        ) {
            throw new InitialProjectConfigActivationException('Verified bundle does not match the signed fresh-project authorization.');
        }
    }

    private function assertDependencies(VerifiedConfigBundle $verified): void
    {
        $declarations = [];
        foreach ($verified->files() as $file) {
            $declarations[$file->ref()] = $file->dependencies;
        }
        $this->dependencies->resolve($declarations);
    }

    /** @return array<string, ConfigSyncFile> */
    private function syncFiles(): array
    {
        $files = [];
        foreach ($this->repository->list() as $file) {
            $files[$file->ref()] = $file;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function sameToken(?ConfigurationActiveToken $left, ConfigurationActiveToken $right): bool
    {
        return $left instanceof ConfigurationActiveToken
            && hash_equals($left->generationId, $right->generationId)
            && $left->activationSequence === $right->activationSequence;
    }
}
