<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase13;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\User\Middleware\CsrfMiddleware;

/**
 * Integration test: CSRF contract through the full HttpKernel pipeline.
 *
 * Exercises every observable from the contract in
 * kitty-specs/inertia-file-upload-csrf-01KQZJQJ/contracts/csrf-token-cookie.md.
 *
 * § 1 — Cookie write contract (T009)
 * § 2 — X-XSRF-TOKEN header acceptance (T010)
 * § 3 — Negative: no token → 403 (T011)
 * § 4 — Regression: application/json exempt (T012)
 *
 * Architecture note
 * -----------------
 * The kernel runs in a subprocess (via csrf_kernel_runner.php) to ensure a
 * fully isolated PHP process with proper superglobal state. A shared session
 * save path allows the same session file to be reused across requests within a
 * single test case, making the CSRF cookie → header round-trip testable without
 * mocking any middleware.
 */
#[CoversClass(CsrfMiddleware::class)]
final class InertiaMultipartCsrfIntegrationTest extends TestCase
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
        $this->projectRoot  = sys_get_temp_dir() . '/waaseyaa_csrf_http_' . uniqid();
        $this->sessionPath  = sys_get_temp_dir() . '/waaseyaa_csrf_sess_' . uniqid();
        $this->providerFile = __DIR__ . '/Fixtures/CsrfTestServiceProvider.php';
        $this->runner       = __DIR__ . '/Fixtures/csrf_kernel_runner.php';

        // Create the temp project directory structure.
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/vendor/composer', 0o755, true);
        mkdir($this->sessionPath, 0o755, true);

        // Create a custom autoload.php that first registers the worktree's
        // modified packages with prepend priority, then falls through to the
        // main repo's real autoloader. This ensures the worktree's
        // CsrfMiddleware and HttpKernel changes are loaded in subprocesses.
        $this->writeAutoloadWrapper();

        // Minimal framework config.
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::foundation($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::auth($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::audit($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::cache($database);

        // Root composer.json declaring our fixture provider so the kernel's
        // PackageManifestCompiler picks it up via extra.waaseyaa.providers.
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name'  => 'waaseyaa/csrf-integration-test',
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

        // Reset session state so subsequent tests start clean.
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    // -----------------------------------------------------------------------
    // T009 — GET sets XSRF-TOKEN cookie (contract §1)
    // -----------------------------------------------------------------------

    #[Test]
    public function getRequestSetsXsrfTokenCookieWithCorrectAttributes(): void
    {
        $result = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);

        $this->assertSame(200, $result['status'], 'GET /test/protected must return 200');

        // Find the XSRF-TOKEN Set-Cookie header.
        $cookieHeader = $this->findSetCookieHeader('XSRF-TOKEN', $result['headers']);
        $this->assertNotNull($cookieHeader, 'Response must include a Set-Cookie header for XSRF-TOKEN');

        // Extract the raw cookie value (URL-encoded form).
        $encodedValue = $this->extractCookieValue($cookieHeader);
        $this->assertNotEmpty($encodedValue, 'XSRF-TOKEN cookie value must not be empty');

        // URL-decoded value must equal the session CSRF token.
        $decodedValue = rawurldecode($encodedValue);
        $sessionToken = $this->readSessionToken($result['session_id']);
        $this->assertSame(
            $sessionToken,
            $decodedValue,
            'URL-decoded cookie value must equal $_SESSION["_csrf_token"]',
        );

        // Contract §1 — cookie attributes.
        $this->assertCookieAttribute($cookieHeader, 'Path=/', 'Cookie must have Path=/');
        $this->assertCookieAttribute($cookieHeader, 'SameSite=Lax', 'Cookie must have SameSite=Lax');
        $this->assertCookieAttributeAbsent($cookieHeader, 'HttpOnly', 'Cookie must NOT have HttpOnly (JS must read it)');
        $this->assertCookieAttributeAbsent($cookieHeader, 'Secure', 'Cookie must NOT have Secure on HTTP');
        $this->assertCookieAttributeAbsent($cookieHeader, 'Domain=', 'Cookie must NOT have Domain attribute');
        $this->assertCookieAttributeAbsent($cookieHeader, 'Max-Age=', 'Cookie must be session-lifetime (no Max-Age)');
        $this->assertCookieAttributeAbsent($cookieHeader, 'Expires=', 'Cookie must be session-lifetime (no Expires)');
    }

    #[Test]
    public function providerMiddlewareAndFrameworkSecurityDecorateTheRealRenderedResponse(): void
    {
        $result = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('<p>CSRF test page</p>', $result['body']);
        $this->assertSame('200', $this->findHeader('X-App-Observed-Status', $result['headers']));
        $this->assertSame(
            hash('sha256', $result['body']),
            $this->findHeader('X-App-Observed-Body-Sha256', $result['headers']),
            'Provider middleware must unwind over the final rendered response body.',
        );
        $this->assertSame('nosniff', $this->findHeader('X-Content-Type-Options', $result['headers']));
        $this->assertSame('SAMEORIGIN', $this->findHeader('X-Frame-Options', $result['headers']));
        $this->assertSame("default-src 'none'", $this->findHeader('Content-Security-Policy', $result['headers']));
        $this->assertSame('max-age=3600; includeSubDomains', $this->findHeader('Strict-Transport-Security', $result['headers']));
        $this->assertNotNull($this->findSetCookieHeader('XSRF-TOKEN', $result['headers']));
    }

    #[Test]
    public function throwingTerminalDispatchSetupRetainsTheKernelUnhandledExceptionSurface(): void
    {
        $result = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/throws',
        ]);

        $this->assertSame(500, $result['status']);
        $payload = json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            'An unexpected error occurred.',
            $payload['errors'][0]['detail'] ?? null,
            'Terminal dispatch exceptions must bubble to HttpKernel::handle(), not be reclassified as middleware failures.',
        );
    }

    // -----------------------------------------------------------------------
    // T010 — Multipart POST with X-XSRF-TOKEN succeeds (contract §2, §3 happy path)
    // -----------------------------------------------------------------------

    #[Test]
    public function multipartPostWithXsrfTokenHeaderSucceeds(): void
    {
        // Step 1: GET to obtain the XSRF-TOKEN cookie.
        $getResult = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);

        $this->assertSame(200, $getResult['status'], 'GET must succeed before POST test');

        $cookieHeader = $this->findSetCookieHeader('XSRF-TOKEN', $getResult['headers']);
        $this->assertNotNull($cookieHeader, 'GET must set XSRF-TOKEN cookie before POST test');

        $encodedValue = $this->extractCookieValue($cookieHeader);
        $decodedValue = rawurldecode($encodedValue);
        $sessionId    = $getResult['session_id'];

        // Step 2: POST multipart with the URL-decoded value as X-XSRF-TOKEN.
        $boundary = 'TestBoundary' . uniqid();
        $body     = $this->buildMultipartBody($boundary, [
            'field1' => 'value1',
        ], [
            'upload' => ['filename' => 'test.txt', 'content' => 'hello world', 'type' => 'text/plain'],
        ]);

        $postResult = $this->dispatch([
            'method'       => 'POST',
            'uri'          => '/test/protected',
            'session_id'   => $sessionId,
            'headers'      => ['X-XSRF-TOKEN' => $decodedValue],
            'body'         => $body,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
        ]);

        $this->assertContains(
            $postResult['status'],
            [200, 302],
            sprintf(
                'Multipart POST with valid X-XSRF-TOKEN must return 200 or 302, got %d. Body: %s',
                $postResult['status'],
                $postResult['body'],
            ),
        );
        $this->assertNotSame(403, $postResult['status'], 'Valid X-XSRF-TOKEN must not return 403');
    }

    // -----------------------------------------------------------------------
    // T011 — Multipart POST without token returns exactly 403 (contract §3)
    // -----------------------------------------------------------------------

    #[Test]
    public function multipartPostWithoutTokenReturnsForbidden(): void
    {
        // First obtain a session so CSRF token exists.
        $getResult = $this->dispatch([
            'method' => 'GET',
            'uri'    => '/test/protected',
        ]);
        $this->assertSame(200, $getResult['status']);
        $sessionId = $getResult['session_id'];

        // POST multipart with no X-XSRF-TOKEN header and no _csrf_token field.
        $boundary = 'TestBoundary' . uniqid();
        $body     = $this->buildMultipartBody($boundary, ['field1' => 'value1'], []);

        $result = $this->dispatch([
            'method'       => 'POST',
            'uri'          => '/test/protected',
            'session_id'   => $sessionId,
            'body'         => $body,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
        ]);

        $this->assertSame(
            403,
            $result['status'],
            sprintf(
                'Multipart POST without token must return exactly 403, got %d. Body: %s',
                $result['status'],
                $result['body'],
            ),
        );

        // The existing "Invalid Security Token" body must be preserved (§5).
        $this->assertStringContainsString(
            'Invalid Security Token',
            $result['body'],
            '403 response body must contain "Invalid Security Token"',
        );
    }

    // -----------------------------------------------------------------------
    // T012 — JSON POST without token is exempt (contract §2, §5 regression)
    // -----------------------------------------------------------------------

    #[Test]
    public function jsonPostWithoutTokenIsExemptFromCsrf(): void
    {
        $result = $this->dispatch([
            'method'       => 'POST',
            'uri'          => '/test/api/json-route',
            'body'         => '{"data":"test"}',
            'content_type' => 'application/json',
        ]);

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], sprintf(
            'JSON POST without CSRF token must be 2xx (exempt), got %d. Body: %s',
            $result['status'],
            $result['body'],
        ));
    }

    // -----------------------------------------------------------------------
    // Helpers
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

        // The last non-empty line is the JSON payload.
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

    /** @param list<string> $headers */
    private function findHeader(string $name, array $headers): ?string
    {
        $prefix = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), $prefix)) {
                return trim(substr($header, strlen($prefix)));
            }
        }

        return null;
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
            // Extract the name=value portion (first segment after "set-cookie: ").
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
     * Extract the raw (possibly URL-encoded) value of a cookie from the
     * "name=value; attr1; attr2" string.
     */
    private function extractCookieValue(string $cookieString): string
    {
        $firstPart = explode(';', $cookieString)[0];
        [, $value]  = explode('=', $firstPart, 2) + ['', ''];

        return trim((string) $value);
    }

    /**
     * Read $_SESSION['_csrf_token'] from the session file written by the
     * subprocess, identified by its session ID.
     */
    private function readSessionToken(string $sessionId): string
    {
        if ($sessionId === '') {
            return '';
        }

        // PHP file-based sessions are stored as "sess_<id>" in the save path.
        $sessionFile = $this->sessionPath . '/sess_' . $sessionId;
        if (!is_file($sessionFile)) {
            return '';
        }

        $data    = file_get_contents($sessionFile);
        $decoded = [];
        // Use the session_decode approach: temporarily swap save path and load.
        $prevPath = session_save_path();
        $prevId   = session_id();
        $prevStatus = session_status();

        if ($prevStatus === \PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_save_path($this->sessionPath);
        session_id($sessionId);
        session_start(['read_and_close' => true]);
        $decoded = $_SESSION;

        // Restore.
        session_save_path($prevPath);
        if ($prevId !== '') {
            session_id($prevId);
        }

        return (string) ($decoded['_csrf_token'] ?? '');
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

    private function assertCookieAttributeAbsent(string $cookieString, string $attribute, string $message): void
    {
        $parts = array_map('trim', explode(';', $cookieString));
        foreach ($parts as $part) {
            if (strtolower($part) === strtolower($attribute) || str_starts_with(strtolower($part), strtolower($attribute))) {
                $this->fail(sprintf('%s — found "%s" in cookie: %s', $message, $part, $cookieString));
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Build a multipart/form-data body string.
     *
     * @param array<string, string>                                          $fields
     * @param array<string, array{filename: string, content: string, type: string}> $files
     */
    private function buildMultipartBody(string $boundary, array $fields, array $files): string
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n";
            $body .= "\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($files as $name => $file) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['filename'] . '"' . "\r\n";
            $body .= 'Content-Type: ' . $file['type'] . "\r\n";
            $body .= "\r\n";
            $body .= $file['content'] . "\r\n";
        }

        $body .= '--' . $boundary . '--' . "\r\n";

        return $body;
    }

    /**
     * Write a custom vendor/autoload.php to the temp project that prepends the
     * worktree's packages/* source paths so that modified framework classes
     * (CsrfMiddleware, HttpKernel) take precedence over the main repo copies.
     *
     * PSR-4 mapping strategy: each package dir `packages/<pkg>/src/` maps to the
     * namespace prefix `Waaseyaa\<PascalCase(pkg)>\`. We build an explicit map
     * of namespace-prefix → directory and register a prepended autoloader that
     * matches on the longest prefix first.
     */
    private function writeAutoloadWrapper(): void
    {
        $repoRoot     = $this->repoRoot;
        $worktreeRoot = $this->worktreeRoot;

        // Copy the main repo's vendor/composer files needed by the kernel's
        // PackageManifestCompiler (installed.json, etc.).
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

        // Build a PSR-4 map from the worktree's packages.
        // Each packages/<name>/src/ maps to Waaseyaa\<PascalName>\.
        // We read the actual package composer.json to get the correct namespace prefix.
        $psr4Map = [];
        foreach (glob($worktreeRoot . '/packages/*/src', GLOB_ONLYDIR) as $srcDir) {
            $pkgDir      = dirname($srcDir);
            $composerFile = $pkgDir . '/composer.json';
            if (!is_file($composerFile)) {
                continue;
            }
            $composerData = json_decode((string) file_get_contents($composerFile), true);
            $autoload     = $composerData['autoload']['psr-4'] ?? [];
            foreach ($autoload as $prefix => $relPath) {
                // Normalise: ensure trailing backslash on prefix.
                $prefix = rtrim($prefix, '\\') . '\\';
                $psr4Map[$prefix] = $pkgDir . '/' . rtrim((string) $relPath, '/');
            }
        }

        // Sort by descending length so more-specific prefixes match first.
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
            // Loads the real Composer autoloader first (it registers itself with prepend),
            // then adds the worktree PSR-4 override on top with prepend so it wins.
            // Order matters: load real autoloader first, then prepend our override.

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
     *
     * In a git worktree the worktree directory itself has no vendor/; the real
     * vendor lives in the main repository. We walk up from __FILE__ looking for
     * vendor/autoload.php, falling back to parsing the .git pointer file.
     */
    private function resolveRepoRoot(): string
    {
        // Fast path: walk up from the test file.
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            if (is_file($dir . '/vendor/autoload.php')) {
                return $dir;
            }
        }

        // Worktree fallback: read the .git pointer file to find the main repo.
        $worktreeRoot = (string) realpath(__DIR__ . '/../../..');
        $gitFile = $worktreeRoot . '/.git';
        if (is_file($gitFile)) {
            $content = (string) file_get_contents($gitFile);
            if (preg_match('/^gitdir:\s*(.+)$/m', $content, $m)) {
                // .git/worktrees/<name> → main repo is three levels up.
                $gitDir  = trim($m[1]);
                $mainRepo = dirname(dirname(dirname($gitDir)));
                if (is_file($mainRepo . '/vendor/autoload.php')) {
                    return $mainRepo;
                }
            }
        }

        // Last resort: the worktree root itself (will fail if vendor is absent).
        return $worktreeRoot;
    }

    private function buildConfigFile(): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database'    => '{$databasePath}',
                'environment' => 'testing',
                'app'         => ['url' => 'http://localhost', 'name' => 'CSRF Integration Test'],
                'cors_origins' => ['http://localhost:3000'],
                'security_headers' => [
                    'csp' => "default-src 'none'",
                    'hsts_enabled' => true,
                    'hsts_max_age' => 3600,
                    'frame_options' => 'SAMEORIGIN',
                ],
            ];
            PHP;
    }

}
