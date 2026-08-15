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
    public function build(array $parent, string $workspace, AdminBuildPlatform $platform): AdminBuildEnvironment
    {
        $workspace = $this->validatedDirectory($workspace, 'workspace-invalid');
        $path = $this->validatedPath($parent['PATH'] ?? '', $platform);
        $npm = $this->resolveBinary('npm', 'NPM_BINARY', $parent, $path, $platform);
        $node = $this->resolveBinary('node', 'NODE_BINARY', $parent, $path, $platform);

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
        $cache = $this->createDirectory($workspace . '/npm-cache');
        $xdgCache = $this->createDirectory($workspace . '/xdg-cache');
        $userConfig = $this->createEmptyFile($workspace . '/npm-user.conf');
        $globalConfig = $this->createEmptyFile($workspace . '/npm-global.conf');
        $variables += [
            'HOME' => $home,
            'TMPDIR' => $temp,
            'XDG_CACHE_HOME' => $xdgCache,
            'npm_config_cache' => $cache,
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
