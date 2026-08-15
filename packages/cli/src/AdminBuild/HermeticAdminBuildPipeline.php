<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

final class HermeticAdminBuildPipeline
{
    public function __construct(
        private readonly HermeticBuildEnvironmentFactory $environmentFactory = new HermeticBuildEnvironmentFactory(),
        private readonly AdminBuildInputPolicy $inputPolicy = new AdminBuildInputPolicy(),
        private readonly AdminBuildToolchainPolicy $toolchainPolicy = new AdminBuildToolchainPolicy(),
        private readonly AdminBuildSourceWorkspace $sourceWorkspace = new AdminBuildSourceWorkspace(),
        private readonly AdminBuildArtifactScanner $artifactScanner = new AdminBuildArtifactScanner(),
        private readonly AdminBuildOutputPublisher $outputPublisher = new AdminBuildOutputPublisher(),
        private readonly AdminBuildProcessRunnerInterface $processRunner = new AdminBuildProcessRunner(),
    ) {}

    /**
     * @param array<string, string> $parentEnvironment
     * @param callable(string): void $stdout
     * @param callable(string): void $stderr
     */
    public function run(
        string $projectRoot,
        string $adminPath,
        array $parentEnvironment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
        ?AdminBuildPlatform $platform = null,
    ): AdminBuildArtifactReport {
        $allowPublicRegistry = ($parentEnvironment['WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY'] ?? '') === '1';
        if (isset($parentEnvironment['WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY'])
            && !in_array($parentEnvironment['WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY'], ['', '0', '1'], true)) {
            throw new AdminBuildPolicyException('dependency-network-policy-invalid');
        }
        $workspace = $this->createWorkspace();
        try {
            $environment = $this->environmentFactory->build(
                $parentEnvironment,
                $workspace,
                $platform ?? AdminBuildPlatform::host(),
            );
            $inputFingerprint = $this->inputPolicy->validate($adminPath, $environment->variables);
            $nodeVersion = $this->toolchainPolicy->validate(
                $projectRoot,
                $environment,
                $this->processRunner,
                $sanitizer,
            );
            $buildPath = $workspace . '/source';
            $sourceFingerprint = $this->sourceWorkspace->prepare($adminPath, $buildPath);
            $emptyDotenv = $workspace . '/empty.env';
            $handle = @fopen($emptyDotenv, 'x');
            if (!is_resource($handle)) {
                throw new AdminBuildPolicyException('dotenv-guard-create-failed');
            }
            fclose($handle);
            chmod($emptyDotenv, 0o600);

            $installStdout = '';
            $installStderr = '';
            $installExit = $this->processRunner->run(
                command: $this->installCommand($environment->npmExecutable, offline: true),
                cwd: $buildPath,
                environment: $environment->variables,
                sanitizer: $sanitizer,
                stdout: static function (string $text) use (&$installStdout): void {
                    $installStdout .= $text;
                },
                stderr: static function (string $text) use (&$installStderr): void {
                    $installStderr .= $text;
                },
            );
            if ($installExit !== 0 && $allowPublicRegistry
                && str_contains($installStdout . $installStderr, 'ENOTCACHED')) {
                $stdout("Admin dependency cache incomplete; using the authorized credential-free public registry.\n");
                $networkEnvironment = $environment->variables;
                $networkEnvironment['npm_config_offline'] = 'false';
                $networkEnvironment['npm_config_registry'] = 'https://registry.npmjs.org/';
                ksort($networkEnvironment, SORT_STRING);
                $installExit = $this->processRunner->run(
                    command: $this->installCommand($environment->npmExecutable, offline: false),
                    cwd: $buildPath,
                    environment: $networkEnvironment,
                    sanitizer: $sanitizer,
                    stdout: $stdout,
                    stderr: $stderr,
                );
            } else {
                if ($installStdout !== '') {
                    $stdout($installStdout);
                }
                if ($installStderr !== '') {
                    $stderr($installStderr);
                }
            }
            if ($installExit !== 0) {
                throw new AdminBuildProcessException('dependency-install-failed');
            }

            $generateExit = $this->processRunner->run(
                command: [$environment->npmExecutable, 'run', 'generate', '--', '--dotenv', $emptyDotenv],
                cwd: $buildPath,
                environment: $environment->variables,
                sanitizer: $sanitizer,
                stdout: $stdout,
                stderr: $stderr,
            );
            if ($generateExit !== 0) {
                throw new AdminBuildProcessException('generate-failed');
            }

            $this->artifactScanner->scan($projectRoot, $buildPath);
            $this->outputPublisher->publish($buildPath . '/.output', $adminPath);
            $report = $this->artifactScanner->scan($projectRoot, $adminPath);

            return new AdminBuildArtifactReport(
                files: $report->files,
                inventoryHash: hash('sha256', $inputFingerprint . ':' . $nodeVersion . ':' . $sourceFingerprint . ':' . $report->inventoryHash),
                clean: $report->clean,
            );
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /** @return non-empty-list<string> */
    private function installCommand(string $npmExecutable, bool $offline): array
    {
        $command = [$npmExecutable, 'ci'];
        $command[] = $offline ? '--offline' : '--registry=https://registry.npmjs.org/';
        array_push($command, '--include=dev', '--ignore-scripts', '--no-audit', '--no-fund');

        return $command;
    }

    private function createWorkspace(): string
    {
        $systemTemp = realpath(sys_get_temp_dir());
        if (!is_string($systemTemp) || !is_dir($systemTemp)) {
            throw new AdminBuildPolicyException('system-temp-invalid');
        }
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $workspace = $systemTemp . '/waaseyaa_admin_build_' . bin2hex(random_bytes(12));
            if (@mkdir($workspace, 0o700)) {
                return $workspace;
            }
        }

        throw new AdminBuildPolicyException('workspace-create-failed');
    }

    private function removeWorkspace(string $workspace): void
    {
        $systemTemp = realpath(sys_get_temp_dir());
        $realWorkspace = realpath($workspace);
        if (!is_string($systemTemp) || !is_string($realWorkspace)
            || !str_starts_with($realWorkspace, $systemTemp . DIRECTORY_SEPARATOR . 'waaseyaa_admin_build_')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realWorkspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($realWorkspace);
    }
}
