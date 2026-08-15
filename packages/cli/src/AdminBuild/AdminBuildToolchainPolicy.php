<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

final class AdminBuildToolchainPolicy
{
    public function validate(
        string $projectRoot,
        AdminBuildEnvironment $environment,
        AdminBuildProcessRunnerInterface $runner,
        RedactorProcessor $sanitizer,
    ): string {
        $pinPath = rtrim($projectRoot, '/\\') . '/.nvmrc';
        $pin = is_file($pinPath) ? trim((string) file_get_contents($pinPath)) : '';
        if (!preg_match('/^[0-9]+$/D', $pin)) {
            throw new AdminBuildPolicyException('node-pin-invalid');
        }

        $stdout = '';
        $stderr = '';
        $exit = $runner->run(
            command: [$environment->nodeExecutable, '--version'],
            cwd: $projectRoot,
            environment: $environment->variables,
            sanitizer: $sanitizer,
            stdout: static function (string $text) use (&$stdout): void {
                $stdout .= $text;
            },
            stderr: static function (string $text) use (&$stderr): void {
                $stderr .= $text;
            },
        );
        $version = trim($stdout);
        if ($exit !== 0 || $stderr !== '' || !preg_match('/^v([0-9]+)\.[0-9]+\.[0-9]+$/D', $version, $match)) {
            throw new AdminBuildPolicyException('node-version-unavailable');
        }
        if ($match[1] !== $pin) {
            throw new AdminBuildPolicyException('node-version-mismatch');
        }

        return $version;
    }
}
