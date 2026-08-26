<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use RuntimeException;

/**
 * Pure manifest, prerequisite, cache, and environment boundary for bin/dev-runtime.
 */
final class DevRuntime
{
    /** @return array<string, mixed> */
    public static function loadManifest(string $path, string $repositoryRoot): array
    {
        $bytes = self::readFile($path);
        $manifest = self::decodeObject($bytes, $path);
        self::requireExactKeys($manifest, ['schema_version', 'profile', 'tools'], 'manifest');
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('dev runtime manifest schema_version must be 1');
        }
        if (($manifest['profile'] ?? null) !== 'wsl2-ubuntu-24.04-x86_64') {
            throw new RuntimeException('dev runtime manifest profile must be wsl2-ubuntu-24.04-x86_64');
        }
        $tools = self::objectAt($manifest, 'tools');
        self::requireExactKeys($tools, ['node', 'composer', 'frankenphp'], 'manifest.tools');

        $node = self::objectAt($tools, 'node');
        self::requireExactKeys($node, ['version', 'archive', 'root', 'url', 'sha256', 'installed'], 'manifest.tools.node');
        self::assertArtifact($node, 'manifest.tools.node');
        if (!str_starts_with((string) $node['archive'], (string) $node['root']) || !str_ends_with((string) $node['archive'], '.tar.xz')) {
            throw new RuntimeException('manifest.tools.node archive/root relationship is invalid');
        }
        $installed = self::objectAt($node, 'installed');
        self::requireExactKeys($installed, ['node', 'npm', 'npx'], 'manifest.tools.node.installed');
        foreach ($installed as $binary => $sha256) {
            if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
                throw new RuntimeException("manifest.tools.node.installed.{$binary} must be lowercase SHA-256");
            }
        }

        $composer = self::objectAt($tools, 'composer');
        self::requireExactKeys($composer, ['version', 'asset', 'url', 'sha256'], 'manifest.tools.composer');
        self::assertArtifact($composer, 'manifest.tools.composer');
        if (($composer['asset'] ?? null) !== 'composer.phar') {
            throw new RuntimeException('manifest.tools.composer asset must be composer.phar');
        }

        $frankenphp = self::objectAt($tools, 'frankenphp');
        self::requireExactKeys($frankenphp, ['pin'], 'manifest.tools.frankenphp');
        $pinRelative = $frankenphp['pin'] ?? null;
        if (!is_string($pinRelative) || preg_match('#^tools/[a-z0-9._-]+\.json$#D', $pinRelative) !== 1) {
            throw new RuntimeException('manifest.tools.frankenphp.pin must name one tools/*.json file');
        }
        $pinPath = rtrim($repositoryRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pinRelative);
        $pinBytes = self::readFile($pinPath);
        $pin = self::decodeObject($pinBytes, $pinPath);
        foreach (['version', 'asset', 'url', 'sha256'] as $key) {
            if (!array_key_exists($key, $pin)) {
                throw new RuntimeException("FrankenPHP pin omits {$key}");
            }
        }
        self::assertArtifact($pin, 'FrankenPHP pin');

        $manifest['resolved_tools'] = [
            'node' => $node,
            'composer' => $composer,
            'frankenphp' => $pin,
        ];
        $manifest['manifest_sha256'] = hash('sha256', $bytes . "\0" . $pinBytes);
        $manifest['manifest_bytes'] = $bytes;
        $manifest['pin_bytes'] = $pinBytes;

        return $manifest;
    }

    /** @param list<string> $entries */
    public static function assertArchiveEntriesSafe(array $entries, string $root): void
    {
        if ($root === '' || str_contains($root, '/') || str_contains($root, '\\')) {
            throw new RuntimeException('archive root must be one path component');
        }
        if ($entries === []) {
            throw new RuntimeException('Node archive is empty');
        }
        foreach ($entries as $entry) {
            if ($entry === '' || str_contains($entry, "\0") || str_starts_with($entry, '/') || str_contains($entry, '\\')) {
                throw new RuntimeException("Node archive entry escapes declared root: {$entry}");
            }
            $components = explode('/', rtrim($entry, '/'));
            if ($components[0] !== $root || in_array('..', $components, true) || in_array('.', $components, true)) {
                throw new RuntimeException("Node archive entry escapes declared root: {$entry}");
            }
        }
    }

    /**
     * @param array{os: mixed, architecture: mixed, wsl: mixed, php: mixed, sqlite: mixed, extensions: mixed, commands: mixed} $system
     * @return list<string>
     */
    public static function systemErrors(array $system): array
    {
        $errors = [];
        if (($system['os'] ?? null) !== '24.04') {
            $errors[] = 'development runtime requires Ubuntu 24.04';
        }
        if (($system['architecture'] ?? null) !== 'x86_64') {
            $errors[] = 'development runtime requires x86_64';
        }
        if (($system['wsl'] ?? null) !== true) {
            $errors[] = 'development runtime requires WSL2';
        }
        $php = $system['php'] ?? null;
        if (!is_string($php) || version_compare($php, '8.5.0', '<') || !version_compare($php, '8.6.0', '<')) {
            $errors[] = 'development runtime requires PHP 8.5';
        }
        $sqlite = $system['sqlite'] ?? null;
        if (!is_string($sqlite) || version_compare($sqlite, '3.40.0', '<') || !version_compare($sqlite, '4.0.0', '<')) {
            $errors[] = 'development runtime requires SQLite >=3.40 and <4.0';
        }
        $extensions = is_array($system['extensions'] ?? null) ? $system['extensions'] : [];
        foreach (['json', 'openssl', 'pdo_sqlite', 'Phar', 'sodium', 'sqlite3'] as $extension) {
            if (!in_array($extension, $extensions, true)) {
                $errors[] = "development runtime requires PHP extension {$extension}";
            }
        }
        $commands = is_array($system['commands'] ?? null) ? $system['commands'] : [];
        foreach (['tar', 'xz'] as $command) {
            if (($commands[$command] ?? false) !== true) {
                $errors[] = "development runtime requires command {$command}";
            }
        }

        return $errors;
    }

    public static function repairAction(): string
    {
        return 'Provision the supported WSL2 Ubuntu 24.04 PHP 8.5 CLI prerequisites, then rerun `bin/dev-runtime bootstrap`.';
    }

    public static function cachePath(string $cacheRoot, string $manifestBytes, string $referencedBytes = ''): string
    {
        return rtrim($cacheRoot, '/\\') . '/waaseyaa/dev-runtime/' . hash('sha256', $manifestBytes . ($referencedBytes === '' ? '' : "\0" . $referencedBytes));
    }

    /**
     * @param array<string, array{path: string, sha256: string}> $artifacts
     * @return list<string>
     */
    public static function artifactErrors(string $directory, array $artifacts): array
    {
        $errors = [];
        foreach ($artifacts as $name => $artifact) {
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $artifact['path']);
            if (!is_file($path)) {
                $errors[] = "managed artifact {$name} is missing";
                continue;
            }
            if (!hash_equals($artifact['sha256'], (string) hash_file('sha256', $path))) {
                $errors[] = "managed artifact {$name} checksum mismatch";
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $manifest @return array<string, array{path: string, sha256: string}> */
    public static function expectedArtifacts(array $manifest): array
    {
        $node = $manifest['resolved_tools']['node'];

        return [
            'node' => ['path' => 'bin/node', 'sha256' => $node['installed']['node']],
            'npm' => ['path' => 'bin/npm', 'sha256' => $node['installed']['npm']],
            'npx' => ['path' => 'bin/npx', 'sha256' => $node['installed']['npx']],
            'composer' => ['path' => 'bin/composer', 'sha256' => $manifest['resolved_tools']['composer']['sha256']],
            'frankenphp' => ['path' => 'bin/frankenphp', 'sha256' => $manifest['resolved_tools']['frankenphp']['sha256']],
        ];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $manifest @return list<string> */
    public static function stateErrors(array $state, array $manifest): array
    {
        $errors = [];
        if (($state['schema_version'] ?? null) !== 1) {
            $errors[] = 'managed runtime state schema is invalid';
        }
        if (($state['manifest_sha256'] ?? null) !== $manifest['manifest_sha256']) {
            $errors[] = 'managed runtime state manifest identity mismatch';
        }
        $versions = [
            'node' => $manifest['resolved_tools']['node']['version'],
            'composer' => $manifest['resolved_tools']['composer']['version'],
            'frankenphp' => $manifest['resolved_tools']['frankenphp']['version'],
        ];
        if (($state['versions'] ?? null) !== $versions) {
            $errors[] = 'managed runtime state versions differ from the manifest';
        }
        if (($state['artifacts'] ?? null) !== self::expectedArtifacts($manifest)) {
            $errors[] = 'managed runtime state artifact inventory differs from the manifest';
        }

        return $errors;
    }

    /** @param array<string, string> $parent @return array<string, string> */
    public static function childEnvironment(array $parent, string $cacheBin): array
    {
        $path = $parent['PATH'] ?? '';
        $parent['PATH'] = rtrim($cacheBin, '/\\') . ($path === '' ? '' : PATH_SEPARATOR . $path);
        $parent['WAASEYAA_DEV_RUNTIME_BIN'] = rtrim($cacheBin, '/\\');

        return $parent;
    }

    /** @param non-empty-list<string> $command @return non-empty-list<string> */
    public static function resolveChildCommand(array $command, string $cacheBin): array
    {
        $executable = $command[0];
        if ($executable === '' || str_contains($executable, '/') || str_contains($executable, '\\')) {
            return $command;
        }

        $managed = ['node', 'npm', 'npx', 'composer', 'frankenphp'];
        $candidate = rtrim($cacheBin, '/\\') . DIRECTORY_SEPARATOR . $executable;
        if (is_file($candidate) && is_executable($candidate)) {
            $command[0] = $candidate;

            return $command;
        }
        if (in_array($executable, $managed, true)) {
            throw new RuntimeException("managed command {$executable} is missing from the verified cache");
        }

        return $command;
    }

    /** @return array<string, mixed> */
    public static function captureSystem(): array
    {
        $osRelease = is_file('/etc/os-release') ? parse_ini_file('/etc/os-release') : false;
        $procVersion = is_file('/proc/version') ? (string) file_get_contents('/proc/version') : '';

        return [
            'php' => PHP_VERSION,
            'composer' => self::versionFromCommand(['composer', '--no-ansi', '--version'], '/Composer version (\d+\.\d+\.\d+)/'),
            'node' => self::versionFromCommand(['node', '--version'], '/v?(\d+\.\d+\.\d+)/'),
            'npm' => self::versionFromCommand(['npm', '--version'], '/(\d+\.\d+\.\d+)/'),
            'frankenphp' => self::versionFromCommand(['frankenphp', 'version'], '/v?(\d+\.\d+\.\d+)/'),
            'sqlite' => class_exists(\SQLite3::class) ? \SQLite3::version()['versionString'] : null,
            'os' => is_array($osRelease) ? ($osRelease['VERSION_ID'] ?? null) : null,
            'architecture' => php_uname('m'),
            'wsl' => preg_match('/microsoft|wsl/i', php_uname('r') . "\n" . $procVersion) === 1,
            'extensions' => get_loaded_extensions(),
            'commands' => [
                'tar' => self::commandExists('tar'),
                'xz' => self::commandExists('xz'),
            ],
            'runner_image_os' => getenv('ImageOS') ?: null,
            'runner_image_version' => getenv('ImageVersion') ?: null,
        ];
    }

    /**
     * @param array<string, mixed> $system
     * @param array<string, mixed> $managed
     * @return array<string, mixed>
     */
    public static function identity(array $system, array $managed, string $manifestDigest, ?string $commit, string $mode, array $errors): array
    {
        return [
            'schema_version' => 1,
            'profile' => 'wsl2-ubuntu-24.04-x86_64',
            'manifest_sha256' => $manifestDigest,
            'result' => $errors === [] ? 'pass' : 'fail',
            'mode' => $mode,
            'commit' => $commit,
            'checked_at' => gmdate('c'),
            'system' => $system,
            'managed' => $managed,
            'errors' => array_values($errors),
            'repair' => $errors === [] ? null : self::repairAction(),
        ];
    }

    /** @param list<string> $command */
    private static function versionFromCommand(array $command, string $pattern): ?string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $output = stream_get_contents($pipes[1]) . "\n" . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || preg_match($pattern, $output, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private static function commandExists(string $command): bool
    {
        $path = getenv('PATH');
        if (!is_string($path)) {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $artifact */
    private static function assertArtifact(array $artifact, string $path): void
    {
        if (!is_string($artifact['url'] ?? null) || preg_match('#^https://#', $artifact['url']) !== 1) {
            throw new RuntimeException("{$path}.url must use HTTPS");
        }
        if (!is_string($artifact['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $artifact['sha256']) !== 1) {
            throw new RuntimeException("{$path}.sha256 must be lowercase SHA-256");
        }
        if (!is_string($artifact['version'] ?? null) || $artifact['version'] === '') {
            throw new RuntimeException("{$path}.version must be non-empty");
        }
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $bytes, string $path): array
    {
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("expected JSON object: {$path}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $parent @return array<string, mixed> */
    private static function objectAt(array $parent, string $key): array
    {
        $value = $parent[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException("expected object at {$key}");
        }

        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function requireExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException("{$path} keys differ");
        }
    }

    private static function readFile(string $path): string
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException("cannot read {$path}");
        }

        return $bytes;
    }
}
