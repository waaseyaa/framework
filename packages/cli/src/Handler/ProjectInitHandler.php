<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\ProjectInit\ProcOpenProjectInitProcessRunner;
use Waaseyaa\CLI\ProjectInit\ProjectConfigActivationResultParser;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\CLI\ProjectInit\ProjectInitProcessResult;
use Waaseyaa\CLI\ProjectInit\ProjectInitProcessRunnerInterface;
use Waaseyaa\SiteContract\CanonicalJson;

/** @api */
final readonly class ProjectInitHandler
{
    public const ERROR_CHILD_PROTOCOL_INVALID = 'PROJECT_INIT004_CHILD_PROTOCOL_INVALID';
    public const ERROR_INVALID_PROJECT_ROOT = 'PROJECT_INIT005_INVALID_PROJECT_ROOT';
    public const ERROR_CHILD_CLEANUP_FAILED = 'PROJECT_INIT006_CHILD_CLEANUP_FAILED';
    public const ERROR_CONFIGURATION_AUTHORIZATION_INVALID = 'PROJECT_INIT007_CONFIGURATION_AUTHORIZATION_INVALID';

    public function __construct(
        private string $defaultProjectRoot,
        private ?ProjectInitProcessRunnerInterface $runner = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $json = (bool) $io->option('json');
        $dryRun = (bool) $io->option('dry-run');
        $authorizationOption = $io->option('config-authorization');
        $activationRequested = \is_string($authorizationOption) && $authorizationOption !== '';
        $captureOutput = $json;

        if ($activationRequested && !$json) {
            $io->error('project:init --config-authorization requires --json so every activation phase has machine-readable outcome evidence.');

            return 2;
        }

        $projectRoot = $this->resolveProjectRoot($io);
        if ($projectRoot === null) {
            if ($json) {
                $io->writeRaw($this->encodeEnvelope(
                    status: 'failed',
                    sitePhase: $this->sitePhaseNotRun(),
                    installPhase: $this->installPhaseNotRun('site_failed'),
                    errors: [['phase' => 'site', 'code' => self::ERROR_INVALID_PROJECT_ROOT]],
                    activationPhase: $activationRequested ? $this->activationPhaseNotRun('site_failed') : null,
                ));
            } else {
                $io->error('The selected project root is not a Waaseyaa application with composer.json, vendor/autoload.php, and vendor/bin/waaseyaa.');
            }

            return 1;
        }

        $runner = $this->runner ?? new ProcOpenProjectInitProcessRunner();

        $authorization = null;
        if ($activationRequested) {
            try {
                $authorizationPath = $this->resolveInputPath($authorizationOption, $projectRoot);
                $authorizationBytes = is_file($authorizationPath) && !is_link($authorizationPath)
                    ? file_get_contents($authorizationPath)
                    : false;
                if (!\is_string($authorizationBytes)) {
                    throw new \InvalidArgumentException('authorization document is unreadable');
                }
                $authorization = ProjectConfigAuthorization::fromJson($authorizationBytes);
            } catch (\Throwable $exception) {
                $io->writeRaw($this->encodeEnvelope(
                    status: 'failed',
                    sitePhase: $this->sitePhaseNotRun(),
                    installPhase: $this->installPhaseNotRun('site_failed'),
                    errors: [['phase' => 'activation', 'code' => self::ERROR_CONFIGURATION_AUTHORIZATION_INVALID]],
                    activationPhase: $this->activationPhaseNotRun('authorization_refused'),
                ));

                return 2;
            }
        }
        $siteResult = $runner->run($this->buildSiteInitCommand($io, $projectRoot), $projectRoot, $captureOutput);

        if ($siteResult->hasRunnerError()) {
            return $this->finishSiteRunnerFailure($io, $json, $siteResult, $activationRequested);
        }

        $sitePayload = null;
        if ($captureOutput) {
            $sitePayload = $this->decodeSingleJsonObject($siteResult->stdout);
            if ($sitePayload === null) {
                return $this->finishSiteProtocolInvalid($io, $json, $siteResult, $activationRequested);
            }
        }

        if ($siteResult->exitCode !== 0) {
            return $this->finishSiteChildFailure($io, $json, $siteResult, $sitePayload, $activationRequested);
        }

        if ($dryRun) {
            if ($json) {
                $io->writeRaw($this->encodeEnvelope(
                    status: 'previewed',
                    sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                    installPhase: $this->installPhaseNotRun('dry_run'),
                    errors: [],
                    activationPhase: $activationRequested ? $this->activationPhaseNotRun('dry_run') : null,
                ));
            } else {
                $io->writeln('Dry run complete; install:init was not run.');
            }

            return 0;
        }

        if ($activationRequested) {
            $siteIdentity = $this->siteIdentity($sitePayload);
            if ($siteIdentity === null) {
                return $this->finishSiteProtocolInvalid($io, true, $siteResult, true, $sitePayload);
            }
            try {
                $authorization->assertMatches($siteIdentity['manifest'], $siteIdentity['plan']);
            } catch (\InvalidArgumentException) {
                $io->writeRaw($this->encodeEnvelope(
                    status: 'failed',
                    sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                    installPhase: $this->installPhaseNotRun('activation_authorization_mismatch'),
                    errors: [['phase' => 'activation', 'code' => self::ERROR_CONFIGURATION_AUTHORIZATION_INVALID]],
                    activationPhase: $this->activationPhaseNotRun('authorization_mismatch'),
                ));

                return 2;
            }
        }

        $installResult = $runner->run($this->buildInstallInitCommand($io, $projectRoot), $projectRoot, $captureOutput);
        if ($installResult->hasRunnerError()) {
            return $this->finishInstallRunnerFailure($io, $json, $siteResult, $sitePayload, $installResult, $activationRequested);
        }

        if ($installResult->exitCode !== 0) {
            return $this->finishInstallChildFailure($io, $json, $siteResult, $sitePayload, $installResult, $activationRequested);
        }

        if ($activationRequested) {
            $identity = $this->siteIdentity($sitePayload);
            assert($identity !== null);
            $activationResult = $runner->run(
                $this->buildConfigActivateCommand($authorizationOption, $projectRoot),
                $projectRoot,
                true,
            );
            $activationPayload = $activationResult->hasRunnerError()
                ? null
                : $this->decodeSingleJsonObject($activationResult->stdout);
            $activationProtocol = new ProjectConfigActivationResultParser();
            $completed = $activationResult->exitCode === 0
                && $activationPayload instanceof \stdClass
                && $activationProtocol->isCompleted($activationPayload, $authorization);
            if (!$completed) {
                $failureStatus = $activationResult->exitCode !== 0
                    && $activationResult->exitCode !== 130
                    && $activationPayload instanceof \stdClass
                    ? $activationProtocol->failureStatus($activationPayload)
                    : null;
                $phaseStatus = $activationResult->errorCode === ProjectInitProcessResult::ERROR_CHILD_START_FAILED
                    ? 'not_started'
                    : ($failureStatus === 'refused' ? 'failed' : 'uncertain');
                $errors = $activationResult->hasRunnerError()
                    ? [$this->runnerErrorEntry('activation', $activationResult)]
                    : ($failureStatus === null
                        ? [['phase' => 'activation', 'code' => self::ERROR_CHILD_PROTOCOL_INVALID]]
                        : []);
                $io->writeRaw($this->encodeEnvelope(
                    status: $phaseStatus === 'uncertain' ? 'uncertain' : 'failed',
                    sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                    installPhase: $this->installPhaseSucceeded($installResult),
                    errors: $errors,
                    activationPhase: $this->activationPhase($activationResult, $activationPayload, $phaseStatus),
                ));

                return $activationResult->exitCode === 130 ? 130 : 1;
            }
            $io->writeRaw($this->encodeEnvelope(
                status: 'completed',
                sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                installPhase: $this->installPhaseSucceeded($installResult),
                errors: [],
                activationPhase: $this->activationPhase($activationResult, $activationPayload, 'succeeded'),
            ));

            return 0;
        }

        if ($json) {
            $io->writeRaw($this->encodeEnvelope(
                status: 'completed',
                sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                installPhase: $this->installPhaseSucceeded($installResult),
                errors: [],
            ));
        }

        return 0;
    }

    private function resolveProjectRoot(SymfonyCommandIO $io): ?string
    {
        $base = rtrim($this->defaultProjectRoot, '/\\');
        $option = $io->option('project-root');
        $candidate = $option === null || $option === ''
            ? $base
            : ($this->isAbsolutePath((string) $option) ? (string) $option : $base . DIRECTORY_SEPARATOR . (string) $option);

        $canonical = realpath($candidate);
        if ($canonical === false || !is_dir($canonical)) {
            return null;
        }

        $composer = $canonical . DIRECTORY_SEPARATOR . 'composer.json';
        $autoload = $canonical . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $entrypoint = $canonical . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'waaseyaa';
        if (!is_file($composer) || !is_file($autoload) || (!is_file($entrypoint) && !is_link($entrypoint))) {
            return null;
        }

        return $canonical;
    }

    /**
     * @return non-empty-list<string>
     */
    private function buildSiteInitCommand(SymfonyCommandIO $io, string $projectRoot): array
    {
        $command = [
            PHP_BINARY,
            $this->entrypointPath($projectRoot),
            'site:init',
        ];

        $command = $this->forwardOption($io, $command, 'answers', '--answers');
        $command = $this->forwardOption($io, $command, 'decision-receipt', '--decision-receipt');
        $command = $this->forwardOption($io, $command, 'preset', '--preset');

        $command[] = '--project-root';
        $command[] = $projectRoot;

        if ((bool) $io->option('dry-run')) {
            $command[] = '--dry-run';
        }
        if ((bool) $io->option('json')) {
            $command[] = '--json';
        }
        if ((bool) $io->option('yes')) {
            $command[] = '--yes';
        }
        if ($this->requiresNoInteraction($io)) {
            $command[] = '--no-interaction';
        }

        return $command;
    }

    /**
     * @return non-empty-list<string>
     */
    private function buildInstallInitCommand(SymfonyCommandIO $io, string $projectRoot): array
    {
        $command = [
            PHP_BINARY,
            $this->entrypointPath($projectRoot),
            'install:init',
        ];
        if ($this->requiresNoInteraction($io)) {
            $command[] = '--no-interaction';
        }

        return $command;
    }

    /** @return non-empty-list<string> */
    private function buildConfigActivateCommand(string $authorization, string $projectRoot): array
    {
        return [
            PHP_BINARY,
            $this->entrypointPath($projectRoot),
            'project:config:activate',
            '--authorization',
            $this->resolveInputPath($authorization, $projectRoot),
            '--no-interaction',
        ];
    }

    private function requiresNoInteraction(SymfonyCommandIO $io): bool
    {
        return !$io->isInteractive() || (bool) $io->option('json');
    }

    private function entrypointPath(string $projectRoot): string
    {
        return $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'waaseyaa';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function resolveInputPath(string $path, string $projectRoot): string
    {
        return $this->isAbsolutePath($path) ? $path : $projectRoot . DIRECTORY_SEPARATOR . $path;
    }

    /** @return array{manifest: string, plan: string}|null */
    private function siteIdentity(?\stdClass $payload): ?array
    {
        if (!$payload instanceof \stdClass) {
            return null;
        }
        $evaluation = $payload->evaluation ?? null;
        if (!$evaluation instanceof \stdClass) {
            return null;
        }
        $planPayload = $evaluation->plan ?? null;
        if (!$planPayload instanceof \stdClass) {
            return null;
        }
        $manifest = $planPayload->input_digest ?? null;
        $plan = $evaluation->plan_digest ?? null;
        if (!\is_string($manifest) || preg_match('/^[a-f0-9]{64}$/D', $manifest) !== 1
            || !\is_string($plan) || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1
        ) {
            return null;
        }

        return ['manifest' => $manifest, 'plan' => $plan];
    }

    /**
     * @param  non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    private function forwardOption(SymfonyCommandIO $io, array $command, string $name, string $flag): array
    {
        $value = $io->option($name);
        if ($value === null) {
            return $command;
        }

        $command[] = $flag;
        $command[] = (string) $value;

        return $command;
    }

    /**
     * @return \stdClass|null decoded site JSON object preserving object/array semantics
     */
    private function decodeSingleJsonObject(string $output): ?\stdClass
    {
        $trimmed = trim($output, " \t\r\n");
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($trimmed);
        for ($i = 0; $i < $length; ++$i) {
            $char = $trimmed[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                ++$depth;
                continue;
            }
            if ($char === '}') {
                --$depth;
                if ($depth === 0) {
                    if (trim(substr($trimmed, $i + 1)) !== '') {
                        return null;
                    }
                    try {
                        $decoded = json_decode(substr($trimmed, 0, $i + 1), false, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        return null;
                    }

                    return $decoded instanceof \stdClass ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePhaseSucceeded(ProjectInitProcessResult $result, \stdClass $sitePayload): array
    {
        return [
            'command' => 'site:init',
            'status' => 'succeeded',
            'exit_code' => $result->exitCode,
            'result' => $sitePayload,
            'stderr' => $result->stderr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePhaseFailed(ProjectInitProcessResult $result, ?\stdClass $sitePayload): array
    {
        return [
            'command' => 'site:init',
            'status' => 'failed',
            'exit_code' => $result->exitCode,
            'result' => $sitePayload ?? new \stdClass(),
            'stderr' => $result->stderr,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePhaseNotRun(): array
    {
        return [
            'command' => 'site:init',
            'status' => 'failed',
            'exit_code' => 1,
            'result' => new \stdClass(),
            'stderr' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installPhaseSucceeded(ProjectInitProcessResult $result): array
    {
        return [
            'command' => 'install:init',
            'status' => 'succeeded',
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'not_run_reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installPhaseFailed(ProjectInitProcessResult $result): array
    {
        return [
            'command' => 'install:init',
            'status' => 'failed',
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'not_run_reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installPhaseNotRun(string $reason): array
    {
        return [
            'command' => 'install:init',
            'status' => 'not_run',
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'not_run_reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $sitePhase
     * @param array<string, mixed> $installPhase
     * @param list<array<string, mixed>> $errors
     */
    private function encodeEnvelope(
        string $status,
        array $sitePhase,
        array $installPhase,
        array $errors,
        ?array $activationPhase = null,
    ): string {
        $phases = ['site' => $sitePhase, 'install' => $installPhase];
        if ($activationPhase !== null) {
            $phases['activation'] = $activationPhase;
        }

        return CanonicalJson::encode([
            'schema' => 'waaseyaa.project_init_result',
            'version' => $activationPhase === null ? 1 : 2,
            'status' => $status,
            'phases' => $phases,
            'errors' => $errors,
        ]) . "\n";
    }

    /** @return array<string, mixed> */
    private function activationPhaseNotRun(string $reason): array
    {
        return [
            'command' => 'project:config:activate',
            'status' => 'not_started',
            'exit_code' => null,
            'result' => new \stdClass(),
            'stderr' => '',
            'not_run_reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function activationPhase(
        ProjectInitProcessResult $result,
        ?\stdClass $payload,
        string $status,
    ): array {
        return [
            'command' => 'project:config:activate',
            'status' => $status,
            'exit_code' => $result->exitCode,
            'result' => $payload ?? new \stdClass(),
            'stderr' => $result->stderr,
            'not_run_reason' => $status === 'not_started' ? 'child_start_failed' : null,
        ];
    }

    private function finishSiteRunnerFailure(
        SymfonyCommandIO $io,
        bool $json,
        ProjectInitProcessResult $siteResult,
        bool $activationRequested = false,
    ): int {
        if ($json) {
            $io->writeRaw($this->encodeEnvelope(
                status: 'failed',
                sitePhase: $this->sitePhaseFailed($siteResult, null),
                installPhase: $this->installPhaseNotRun('site_failed'),
                errors: [$this->runnerErrorEntry('site', $siteResult)],
                activationPhase: $activationRequested ? $this->activationPhaseNotRun('site_failed') : null,
            ));
        } elseif ($siteResult->errorCode === ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED) {
            $io->error(sprintf(
                'Child cleanup failed for pid %d: %s',
                (int) $siteResult->childPid,
                (string) $siteResult->diagnostic,
            ));
        }

        return 1;
    }

    /**
     * @return array{phase: string, code: string, child_pid?: int, diagnostic?: string}
     */
    private function runnerErrorEntry(string $phase, ProjectInitProcessResult $result): array
    {
        $entry = ['phase' => $phase, 'code' => (string) $result->errorCode];
        if ($result->errorCode === ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED) {
            $entry['child_pid'] = (int) $result->childPid;
            $entry['diagnostic'] = (string) $result->diagnostic;
        }

        return $entry;
    }

    private function finishSiteChildFailure(
        SymfonyCommandIO $io,
        bool $json,
        ProjectInitProcessResult $siteResult,
        ?\stdClass $sitePayload,
        bool $activationRequested = false,
    ): int {
        if ($json) {
            $io->writeRaw($this->encodeEnvelope(
                status: 'failed',
                sitePhase: $this->sitePhaseFailed($siteResult, $sitePayload),
                installPhase: $this->installPhaseNotRun('site_failed'),
                errors: [],
                activationPhase: $activationRequested ? $this->activationPhaseNotRun('site_failed') : null,
            ));
        }

        return $siteResult->exitCode === 130 ? 130 : ($siteResult->exitCode !== 0 ? $siteResult->exitCode : 1);
    }

    private function finishSiteProtocolInvalid(
        SymfonyCommandIO $io,
        bool $json,
        ProjectInitProcessResult $siteResult,
        bool $activationRequested = false,
        ?\stdClass $sitePayload = null,
    ): int {
        if ($json) {
            $io->writeRaw($this->encodeEnvelope(
                status: 'failed',
                sitePhase: $sitePayload instanceof \stdClass && $siteResult->exitCode === 0
                    ? $this->sitePhaseSucceeded($siteResult, $sitePayload)
                    : $this->sitePhaseFailed($siteResult, null),
                installPhase: $this->installPhaseNotRun('site_failed'),
                errors: [['phase' => 'site', 'code' => self::ERROR_CHILD_PROTOCOL_INVALID]],
                activationPhase: $activationRequested ? $this->activationPhaseNotRun('site_protocol_invalid') : null,
            ));
        }

        return 1;
    }

    private function finishInstallRunnerFailure(
        SymfonyCommandIO $io,
        bool $json,
        ProjectInitProcessResult $siteResult,
        ?\stdClass $sitePayload,
        ProjectInitProcessResult $installResult,
        bool $activationRequested = false,
    ): int {
        if ($json) {
            assert($sitePayload instanceof \stdClass);

            $io->writeRaw($this->encodeEnvelope(
                status: 'failed',
                sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                installPhase: $this->installPhaseFailed($installResult),
                errors: [$this->runnerErrorEntry('install', $installResult)],
                activationPhase: $activationRequested ? $this->activationPhaseNotRun('install_failed') : null,
            ));
        } elseif ($installResult->errorCode === ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED) {
            $io->error(sprintf(
                'Child cleanup failed for pid %d: %s',
                (int) $installResult->childPid,
                (string) $installResult->diagnostic,
            ));
        }

        return 1;
    }

    private function finishInstallChildFailure(
        SymfonyCommandIO $io,
        bool $json,
        ProjectInitProcessResult $siteResult,
        ?\stdClass $sitePayload,
        ProjectInitProcessResult $installResult,
        bool $activationRequested = false,
    ): int {
        if ($json) {
            assert($sitePayload instanceof \stdClass);

            $io->writeRaw($this->encodeEnvelope(
                status: 'failed',
                sitePhase: $this->sitePhaseSucceeded($siteResult, $sitePayload),
                installPhase: $this->installPhaseFailed($installResult),
                errors: [],
                activationPhase: $activationRequested ? $this->activationPhaseNotRun('install_failed') : null,
            ));
        }

        return $installResult->exitCode === 130 ? 130 : ($installResult->exitCode !== 0 ? $installResult->exitCode : 1);
    }
}
