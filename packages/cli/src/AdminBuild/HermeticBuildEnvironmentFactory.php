<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

final class HermeticBuildEnvironmentFactory
{
    /** @var list<string> */
    private const PUBLIC_BUILD_VARIABLES = [
        'NUXT_BACKEND_URL',
        'NUXT_PUBLIC_APP_NAME',
        'NUXT_PUBLIC_AUTH_REGISTRATION',
        'NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL',
        'NUXT_PUBLIC_BASE_URL',
        'NUXT_PUBLIC_DOCS_URL',
        'NUXT_PUBLIC_ENABLE_REALTIME',
        'WAASEYAA_ADMIN_BUILD_ID',
    ];

    /**
     * @param array<string, string> $parent
     */
    public function build(
        array $parent,
        string $workspace,
        AdminBuildPlatform $platform,
        string $dependencyCache,
    ): AdminBuildEnvironment {
        $workspace = $this->validatedDirectory($workspace, 'workspace-invalid');
        $dependencyCache = $this->validatedDirectory($dependencyCache, 'dependency-cache-invalid');
        $path = $this->validatedPath($parent['PATH'] ?? '', $platform);
        ['node' => $node, 'npm' => $npm] = $this->resolveToolchainOn($parent, $path, $platform);

        $variables = [
            'CI' => 'true',
            'NODE_ENV' => 'production',
            'NUXT_TELEMETRY_DISABLED' => '1',
            'PATH' => $path,
            'WAASEYAA_ADMIN_BUILD_ID' => 'waaseyaa-hermetic',
            'npm_config_audit' => 'false',
            'npm_config_fund' => 'false',
            'npm_config_offline' => 'true',
            'npm_config_update_notifier' => 'false',
        ];

        foreach (self::PUBLIC_BUILD_VARIABLES as $name) {
            $value = $parent[$name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $variables[$name] = $this->validatePublicBuildValue($name, $value);
        }

        $home = $this->createDirectory($workspace . '/home');
        $temp = $this->createDirectory($workspace . '/tmp');
        $xdgCache = $this->createDirectory($workspace . '/xdg-cache');
        $userConfig = $this->createEmptyFile($workspace . '/npm-user.conf');
        $globalConfig = $this->createEmptyFile($workspace . '/npm-global.conf');
        $variables += [
            'HOME' => $home,
            'TMPDIR' => $temp,
            'XDG_CACHE_HOME' => $xdgCache,
            'npm_config_cache' => $dependencyCache,
            'npm_config_globalconfig' => $globalConfig,
            'npm_config_userconfig' => $userConfig,
        ];

        if ($platform === AdminBuildPlatform::Windows) {
            $pathext = strtoupper($parent['PATHEXT'] ?? '');
            if (!preg_match('/^\.[A-Z0-9]+(?:;\.[A-Z0-9]+)*$/D', $pathext)) {
                throw new AdminBuildPolicyException('windows-pathext-invalid');
            }
            $systemRoot = $this->validatedDirectory($parent['SystemRoot'] ?? '', 'windows-system-root-invalid');
            $comspec = $this->validatedExecutable($parent['COMSPEC'] ?? '', 'windows-comspec-invalid');
            $profile = $this->createDirectory($workspace . '/profile');
            $appData = $this->createDirectory($profile . '/AppData/Roaming');
            $localAppData = $this->createDirectory($profile . '/AppData/Local');
            $windowsTemp = $this->createDirectory($profile . '/Temp');
            unset($variables['TMPDIR']);
            $variables += [
                'APPDATA' => $appData,
                'COMSPEC' => $comspec,
                'LOCALAPPDATA' => $localAppData,
                'PATHEXT' => $pathext,
                'SystemRoot' => $systemRoot,
                'TEMP' => $windowsTemp,
                'TMP' => $windowsTemp,
                'USERPROFILE' => $profile,
            ];
        }

        ksort($variables, SORT_STRING);

        return new AdminBuildEnvironment($npm, $node, $variables);
    }

    /**
     * The node and npm-cli.js the hermetic child will actually execute, resolved
     * from the same sanitized PATH and the same NODE_BINARY / NPM_BINARY
     * overrides as a real build.
     *
     * Callers that need to REPORT the build's runtime must resolve it through
     * here rather than measuring whatever `node` the parent shell happens to
     * find: with NODE_BINARY unset the two can differ, and a runtime the build
     * did not use is not evidence about the build.
     *
     * @param array<string, string> $parent
     *
     * @return array{node: string, npm: string}
     *
     * @api Consumed by bin/run-hermetic-admin-build, outside the analysed path set.
     */
    public function resolveToolchain(array $parent, AdminBuildPlatform $platform): array
    {
        return $this->resolveToolchainOn(
            $parent,
            $this->validatedPath($parent['PATH'] ?? '', $platform),
            $platform,
        );
    }

    /**
     * @param array<string, string> $parent
     *
     * @return array{node: string, npm: string}
     */
    private function resolveToolchainOn(array $parent, string $path, AdminBuildPlatform $platform): array
    {
        $npmLauncher = $this->resolveBinary('npm', 'NPM_BINARY', $parent, $path, $platform);

        return [
            'node' => $this->resolveBinary('node', 'NODE_BINARY', $parent, $path, $platform),
            'npm' => $this->resolveNpmCli($npmLauncher, $platform),
        ];
    }

    private function resolveNpmCli(string $launcher, AdminBuildPlatform $platform): string
    {
        if (basename($launcher) === 'npm-cli.js') {
            return $this->validatedReadableFile($launcher, 'npm-cli-invalid');
        }
        if ($platform === AdminBuildPlatform::Windows) {
            return $this->validatedReadableFile(
                dirname($launcher) . '/node_modules/npm/bin/npm-cli.js',
                'npm-cli-invalid',
            );
        }

        throw new AdminBuildPolicyException('npm-cli-invalid');
    }

    /** @param array<string, string> $parent */
    private function resolveBinary(
        string $name,
        string $overrideName,
        array $parent,
        string $path,
        AdminBuildPlatform $platform,
    ): string {
        $override = $parent[$overrideName] ?? '';
        if ($override !== '') {
            return $this->validatedExecutable($override, 'build-executable-invalid');
        }

        $separator = $platform === AdminBuildPlatform::Windows ? ';' : ':';
        $extensions = $platform === AdminBuildPlatform::Windows
            ? explode(';', strtoupper($parent['PATHEXT'] ?? '.COM;.EXE;.BAT;.CMD'))
            : [''];
        foreach (explode($separator, $path) as $directory) {
            foreach ($extensions as $extension) {
                $candidate = $directory . DIRECTORY_SEPARATOR . $name . $extension;
                if (is_file($candidate) && ($platform === AdminBuildPlatform::Windows || is_executable($candidate))) {
                    return $this->validatedExecutable($candidate, 'build-executable-invalid');
                }
            }
        }

        throw new AdminBuildPolicyException('build-executable-missing');
    }

    private function validatedPath(string $path, AdminBuildPlatform $platform): string
    {
        if ($path === '') {
            throw new AdminBuildPolicyException('path-missing');
        }
        $separator = $platform === AdminBuildPlatform::Windows ? ';' : ':';
        $validated = [];
        foreach (explode($separator, $path) as $entry) {
            if ($entry === '' || !$this->isAbsolute($entry)) {
                throw new AdminBuildPolicyException('path-entry-invalid');
            }
            $validated[] = $this->validatedDirectory($entry, 'path-entry-invalid');
        }

        return implode($separator, array_values(array_unique($validated)));
    }

    private function validatePublicBuildValue(string $name, string $value): string
    {
        if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new AdminBuildPolicyException('public-build-value-invalid');
        }
        if (in_array($name, ['NUXT_BACKEND_URL', 'NUXT_PUBLIC_DOCS_URL'], true)) {
            $parts = parse_url($value);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || !isset($parts['host'])) {
                throw new AdminBuildPolicyException('public-build-url-invalid');
            }
        }
        if ($name === 'NUXT_PUBLIC_BASE_URL' && (!str_starts_with($value, '/') || str_starts_with($value, '//'))) {
            throw new AdminBuildPolicyException('public-build-base-url-invalid');
        }
        if (in_array($name, ['NUXT_PUBLIC_ENABLE_REALTIME', 'NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL'], true)
            && !in_array($value, ['0', '1'], true)) {
            throw new AdminBuildPolicyException('public-build-boolean-invalid');
        }
        if (in_array($name, ['WAASEYAA_ADMIN_BUILD_ID', 'NUXT_PUBLIC_AUTH_REGISTRATION'], true)
            && !preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $value)) {
            throw new AdminBuildPolicyException('public-build-identifier-invalid');
        }

        return $value;
    }

    private function validatedDirectory(string $path, string $errorCode): string
    {
        if (!$this->isAbsolute($path)) {
            throw new AdminBuildPolicyException($errorCode);
        }
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new AdminBuildPolicyException($errorCode);
        }

        return $real;
    }

    private function validatedExecutable(string $path, string $errorCode): string
    {
        if (!$this->isAbsolute($path)) {
            throw new AdminBuildPolicyException($errorCode);
        }
        $real = realpath($path);
        if (!is_string($real) || !is_file($real)) {
            throw new AdminBuildPolicyException($errorCode);
        }
        if (PHP_OS_FAMILY !== 'Windows' && !is_executable($real)) {
            throw new AdminBuildPolicyException($errorCode);
        }

        return $real;
    }

    private function validatedReadableFile(string $path, string $errorCode): string
    {
        if (!$this->isAbsolute($path)) {
            throw new AdminBuildPolicyException($errorCode);
        }
        $real = realpath($path);
        if (!is_string($real) || !is_file($real) || !is_readable($real)) {
            throw new AdminBuildPolicyException($errorCode);
        }

        return $real;
    }

    private function createDirectory(string $path): string
    {
        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            throw new AdminBuildPolicyException('workspace-create-failed');
        }

        return $this->validatedDirectory($path, 'workspace-create-failed');
    }

    private function createEmptyFile(string $path): string
    {
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            throw new AdminBuildPolicyException('npm-config-create-failed');
        }
        fclose($handle);
        chmod($path, 0o600);

        return (string) realpath($path);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
