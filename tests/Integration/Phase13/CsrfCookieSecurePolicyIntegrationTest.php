<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase13;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Integration regression for issue #2149: a forced `session.cookie.secure =>
 * true` must keep the `Secure` attribute on the XSRF-TOKEN cookie even when
 * the request arrives over plaintext HTTP, and a configured `samesite` must
 * govern the CSRF cookie the same way it governs the session cookie.
 *
 * This pins the full kernel wiring (HttpKernel threads the resolved
 * SessionCookiePolicy into CsrfMiddleware), not just the middleware: the
 * production leak on waaseyaa.org happened precisely because the kernel
 * constructed CsrfMiddleware without the configured cookie policy.
 *
 * Architecture note: the kernel runs in a subprocess via csrf_kernel_runner.php
 * (same harness as InertiaMultipartCsrfIntegrationTest) with `HTTPS => off`,
 * so the request is genuinely non-HTTPS end to end.
 */
#[CoversNothing]
final class CsrfCookieSecurePolicyIntegrationTest extends TestCase
{
    /** Main repo root (contains vendor/). */
    private string $repoRoot;
    /** Worktree root (contains modified packages/). */
    private string $worktreeRoot;
    private string $projectRoot;
    private string $sessionPath;
    private string $providerFile;
    private string $runner;

    protected function setUp(): void
    {
        $this->repoRoot     = $this->resolveRepoRoot();
        $this->worktreeRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot  = sys_get_temp_dir() . '/waaseyaa_csrf_secure_' . uniqid();
        $this->sessionPath  = sys_get_temp_dir() . '/waaseyaa_csrf_secure_sess_' . uniqid();
        $this->providerFile = __DIR__ . '/Fixtures/CsrfTestServiceProvider.php';
        $this->runner       = __DIR__ . '/Fixtures/csrf_kernel_runner.php';

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/vendor/composer', 0o755, true);
        mkdir($this->sessionPath, 0o755, true);

        $this->writeAutoloadWrapper();

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::foundation($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::auth($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::audit($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::cache($database);

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name'  => 'waaseyaa/csrf-secure-policy-integration-test',
            'extra' => [
                'waaseyaa' => [
                    'providers' => [
                        \Waaseyaa\Tests\Integration\Phase13\Fixtures\CsrfTestServiceProvider::class,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectRoot);
        new Filesystem()->remove($this->sessionPath);

        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    #[Test]
    public function forcedSecureConfigKeepsSecureXsrfCookieOnPlaintextRequest(): void
    {
        $result = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);

        $this->assertSame(200, $result['status'], 'GET /test/protected must return 200. Body: ' . $result['body']);

        $cookieHeader = $this->findSetCookieHeader('XSRF-TOKEN', $result['headers']);
        $this->assertNotNull($cookieHeader, 'Response must include a Set-Cookie header for XSRF-TOKEN');

        $this->assertCookieAttribute(
            $cookieHeader,
            'Secure',
            'Forced session.cookie.secure=true must keep Secure on XSRF-TOKEN over plaintext HTTP (#2149)',
        );
        $this->assertCookieAttribute(
            $cookieHeader,
            'SameSite=Strict',
            'Configured session.cookie.samesite must govern the XSRF-TOKEN cookie',
        );
    }

    #[Test]
    public function cookieBearingAnonymousHtmlCannotRemainPubliclyCacheable(): void
    {
        $result = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);

        $this->assertSame(200, $result['status'], 'GET /test/protected must return 200. Body: ' . $result['body']);
        $this->assertNotNull(
            $this->findSetCookieHeader('XSRF-TOKEN', $result['headers']),
            'The full-kernel anonymous HTML response must exercise the cookie-bearing boundary.',
        );

        $cacheControl = $this->findHeaderValues('cache-control', $result['headers']);
        $this->assertSame(
            ['no-store, private'],
            $cacheControl,
            'A response carrying Set-Cookie must have one final private Cache-Control policy (#2150).',
        );
    }

    // -----------------------------------------------------------------------
    // Harness (mirrors InertiaMultipartCsrfIntegrationTest)
    // -----------------------------------------------------------------------

    /**
     * Dispatch a request through the full HttpKernel in a subprocess.
     *
     * @param array<string, mixed> $desc Request descriptor.
     * @return array{status: int, headers: list<string>, body: string, session_id: string}
     */
    private function dispatch(array $desc): array
    {
        $desc['repo_root']     = $this->repoRoot;
        $desc['project_root']  = $this->projectRoot;
        $desc['session_path']  = $this->sessionPath;
        $desc['provider_file'] = $this->providerFile;

        $json = json_encode($desc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $jsonFile = $this->projectRoot . '/storage/csrf-request-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($jsonFile, $json);

        $command = sprintf(
            '%s %s --json-file %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->runner),
            escapeshellarg($jsonFile),
        );

        $output = shell_exec($command);
        $this->assertNotNull($output, 'Kernel runner produced no output.');

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
            static fn(string $l): bool => trim($l) !== '',
        ));
        $jsonLine = $lines !== [] ? $lines[count($lines) - 1] : '';
        $payload  = json_decode($jsonLine, true);

        $this->assertIsArray(
            $payload,
            sprintf('Kernel runner returned invalid JSON. Full output: %s', $output),
        );

        return [
            'status'     => (int) ($payload['status'] ?? 0),
            'headers'    => is_array($payload['headers'] ?? null) ? array_values($payload['headers']) : [],
            'body'       => (string) ($payload['body'] ?? ''),
            'session_id' => (string) ($payload['session_id'] ?? ''),
        ];
    }

    /**
     * Find a Set-Cookie header for the named cookie (case-insensitive name).
     *
     * @param list<string> $headers
     */
    private function findSetCookieHeader(string $cookieName, array $headers): ?string
    {
        foreach ($headers as $header) {
            if (!str_starts_with(strtolower($header), 'set-cookie:')) {
                continue;
            }
            $cookiePart = trim(substr($header, strlen('set-cookie:')));
            $firstPart  = explode(';', $cookiePart)[0];
            [$name]     = explode('=', $firstPart, 2);
            if (strtolower(trim($name)) === strtolower($cookieName)) {
                return $cookiePart;
            }
        }

        return null;
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private function findHeaderValues(string $name, array $headers): array
    {
        $prefix = strtolower($name) . ':';
        $values = [];
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                $values[] = trim(substr($header, strlen($prefix)));
            }
        }

        return $values;
    }

    private function assertCookieAttribute(string $cookieString, string $attribute, string $message): void
    {
        $parts = array_map('trim', explode(';', $cookieString));
        $found = false;
        foreach ($parts as $part) {
            if (strtolower($part) === strtolower($attribute) || str_starts_with(strtolower($part), strtolower($attribute))) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, sprintf('%s — cookie string: %s', $message, $cookieString));
    }

    /**
     * Write a custom vendor/autoload.php to the temp project that prepends the
     * worktree's packages/* source paths so that modified framework classes
     * (CsrfMiddleware, HttpKernel, SessionCookiePolicy) take precedence over
     * the main repo copies.
     */
    private function writeAutoloadWrapper(): void
    {
        $repoRoot     = $this->repoRoot;
        $worktreeRoot = $this->worktreeRoot;

        $vendorComposerSrc = $repoRoot . '/vendor/composer';
        $vendorComposerDst = $this->projectRoot . '/vendor/composer';
        foreach (['installed.json', 'installed.php', 'autoload_psr4.php', 'autoload_classmap.php', 'autoload_files.php', 'autoload_namespaces.php'] as $file) {
            if (is_file($vendorComposerSrc . '/' . $file)) {
                copy($vendorComposerSrc . '/' . $file, $vendorComposerDst . '/' . $file);
            }
        }
        mkdir($this->projectRoot . '/vendor/waaseyaa', 0o755, true);
        foreach (glob($worktreeRoot . '/packages/*', GLOB_ONLYDIR) ?: [] as $packageRoot) {
            symlink($packageRoot, $this->projectRoot . '/vendor/waaseyaa/' . basename($packageRoot));
        }

        $psr4Map = [];
        foreach (glob($worktreeRoot . '/packages/*/src', GLOB_ONLYDIR) as $srcDir) {
            $pkgDir       = dirname($srcDir);
            $composerFile = $pkgDir . '/composer.json';
            if (!is_file($composerFile)) {
                continue;
            }
            $composerData = json_decode((string) file_get_contents($composerFile), true);
            $autoload     = $composerData['autoload']['psr-4'] ?? [];
            foreach ($autoload as $prefix => $relPath) {
                $prefix = rtrim($prefix, '\\') . '\\';
                $psr4Map[$prefix] = $pkgDir . '/' . rtrim((string) $relPath, '/');
            }
        }

        krsort($psr4Map);

        $mapPhp = "[\n";
        foreach ($psr4Map as $prefix => $dir) {
            $mapPhp .= '    ' . var_export($prefix, true) . ' => ' . var_export($dir, true) . ",\n";
        }
        $mapPhp .= ']';

        $realAutoload = var_export($repoRoot . '/vendor/autoload.php', true);

        $autoloadContent = <<<PHP
            <?php
            // Custom autoload wrapper for CSRF integration tests.
            // Loads the real Composer autoloader first, then adds the worktree
            // PSR-4 override on top with prepend so it wins.

            require_once {$realAutoload};

            \$worktreePsr4Map = {$mapPhp};

            spl_autoload_register(static function (string \$class) use (\$worktreePsr4Map): void {
                foreach (\$worktreePsr4Map as \$prefix => \$baseDir) {
                    if (!str_starts_with(\$class, \$prefix)) {
                        continue;
                    }
                    \$relative = str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';
                    \$candidate = \$baseDir . '/' . \$relative;
                    if (is_file(\$candidate)) {
                        require_once \$candidate;
                        return;
                    }
                }
            }, prepend: true);
            PHP;

        file_put_contents($this->projectRoot . '/vendor/autoload.php', $autoloadContent);
    }

    /**
     * Resolve the root directory that contains the vendor/ autoloader.
     */
    private function resolveRepoRoot(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            if (is_file($dir . '/vendor/autoload.php')) {
                return $dir;
            }
        }

        $worktreeRoot = (string) realpath(__DIR__ . '/../../..');
        $gitFile = $worktreeRoot . '/.git';
        if (is_file($gitFile)) {
            $content = (string) file_get_contents($gitFile);
            if (preg_match('/^gitdir:\s*(.+)$/m', $content, $m)) {
                $gitDir   = trim($m[1]);
                $mainRepo = dirname(dirname(dirname($gitDir)));
                if (is_file($mainRepo . '/vendor/autoload.php')) {
                    return $mainRepo;
                }
            }
        }

        return $worktreeRoot;
    }

    private function buildConfigFile(): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        // The #2149 production shape: secure pinned true regardless of the
        // request scheme, plus a non-default samesite to prove threading.
        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database'    => '{$databasePath}',
                'environment' => 'testing',
                'app'         => ['url' => 'http://localhost', 'name' => 'CSRF Secure Policy Integration Test'],
                'cors_origins' => ['http://localhost:3000'],
                'security_headers' => [
                    'csp' => "default-src 'none'",
                    'hsts_enabled' => true,
                    'hsts_max_age' => 3600,
                    'frame_options' => 'SAMEORIGIN',
                ],
                'session'     => [
                    'cookie' => [
                        'secure'   => true,
                        'samesite' => 'Strict',
                    ],
                ],
            ];
            PHP;
    }

}
