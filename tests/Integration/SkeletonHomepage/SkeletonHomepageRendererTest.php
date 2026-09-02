<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\SkeletonHomepage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * The generated application must have exactly one homepage renderer: the
 * framework's SSR/Twig fallback (#2651).
 *
 * The skeleton previously bound `/` to an application controller that read
 * `templates/home.html.twig` with `file_get_contents()` and returned those bytes
 * verbatim. That worked only because the shipped template happened to contain no
 * Twig tags; any expression an app author added was served as literal source, and
 * the app route also bypassed SSR theme resolution, cache-max-age, language
 * negotiation and the SSR error pages.
 *
 * This test binds the real `skeleton/src/` tree into a skeleton-shaped project
 * root and drives it through the production `HttpKernel`, so a reintroduced
 * byte-passthrough homepage route fails here rather than in a consumer app.
 */
#[CoversNothing]
final class SkeletonHomepageRendererTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_skeleton_home_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/templates', 0o755, true);

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        $this->copyDirectory($this->repoRoot . '/skeleton/src', $this->projectRoot . '/src');

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/skeleton-homepage-fixture',
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'extra' => ['waaseyaa' => ['providers' => ['App\\Provider\\AppServiceProvider']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());

        // An application-owned homepage override carrying a Twig expression: the
        // exact thing the removed byte-passthrough controller could not render.
        file_put_contents(
            $this->projectRoot . '/templates/home.html.twig',
            "<!doctype html>\n<html lang=\"en\"><body>\n"
            . "<main data-template=\"app-home\"><p id=\"probe\">PROBE-VALUE: {{ 6 * 7 }}</p></main>\n"
            . "</body></html>\n",
        );

        $this->initializeFreshDatabase();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectRoot)) {
            return;
        }

        // Filesystem::remove() rather than a hand-rolled walker: the fixture's
        // vendor/waaseyaa entries are symlinks into packages/, and #2491's
        // RecursiveRemoverContractTest forbids new recursive removers in tests.
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function homepage_twig_expression_is_evaluated_through_the_production_kernel(): void
    {
        $response = $this->request('/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-template="app-home"', $response['body']);
        $this->assertStringContainsString('PROBE-VALUE: 42', $response['body']);
        $this->assertStringNotContainsString(
            '{{ 6 * 7 }}',
            $response['body'],
            'The homepage must render through Twig, not be served as raw template bytes.',
        );
    }

    /**
     * Template overrides keep working: the theme chain puts the application's
     * own `templates/` ahead of `packages/ssr/templates/`, so the app copy — not
     * the framework fallback copy of `home.html.twig` — is what renders.
     */
    #[Test]
    public function application_home_template_still_overrides_the_framework_copy(): void
    {
        $response = $this->request('/');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-template="app-home"', $response['body']);
        $this->assertStringNotContainsString('Edit <code>templates/home.html.twig</code>', $response['body']);
    }

    /**
     * #2438 / ADR-024 regression guard: the mirrored `src/` tree this fixture
     * boots from is exactly `skeleton/src/` as committed — no placeholder
     * directory is added anywhere in this suite. Asserting the exact file set
     * (rather than just that boot succeeds) means a reintroduced empty
     * placeholder directory would still fail here even though PHP silently
     * tolerates an empty `mkdir()`, and `homepage_twig_expression_is_evaluated…`
     * above is the proof that this exact minimal tree boots the production
     * `HttpKernel` and serves a request end to end.
     */
    #[Test]
    public function the_booted_src_tree_carries_no_placeholder_directory(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr((string) $file->getPathname(), strlen($this->projectRoot) + 1));
            }
        }
        sort($files);

        $this->assertSame(
            ['src/Http/BootFailureResponder.php', 'src/Provider/AppServiceProvider.php'],
            $files,
            'The booted src/ tree must be exactly what the minimal skeleton ships — no placeholder directory.',
        );
    }

    /** The clean-URL diagnostic route stays application-owned (#2651 non-goal). */
    #[Test]
    public function application_still_owns_the_clean_url_probe_route(): void
    {
        $response = $this->request(CleanUrlProbe::PATH);

        $this->assertSame(200, $response['status']);
        $this->assertSame(CleanUrlProbe::SENTINEL, trim($response['body']));
    }

    /**
     * @return array{status:int,headers:list<string>,body:string}
     */
    private function request(string $uri, string $method = 'GET'): array
    {
        $payload = $this->runFixture('request', $method, $uri);

        return [
            'status' => (int) ($payload['status'] ?? 0),
            'headers' => is_array($payload['headers'] ?? null) ? array_values($payload['headers']) : [],
            'body' => (string) ($payload['body'] ?? ''),
        ];
    }

    /**
     * Run one fixture action in the subprocess and decode its JSON result.
     *
     * Everything that needs the fixture's `App\` PSR-4 root runs behind this
     * boundary. Registering that root in the PHPUnit process would append a
     * temp-directory prefix to the process-global Composer ClassLoader that
     * teardown cannot remove, leaving order-dependent state behind for the rest
     * of the suite (which also runs under `ci/random-order-*`).
     *
     * @return array<string, mixed>
     */
    private function runFixture(string $action, string ...$arguments): array
    {
        $runner = $this->repoRoot . '/tests/Integration/SkeletonHomepage/Fixtures/skeleton_kernel_runner.php';
        $command = implode(' ', array_map(
            escapeshellarg(...),
            [PHP_BINARY, $runner, $this->repoRoot, $this->projectRoot, $action, ...$arguments],
        )) . ' 2>&1';

        $output = shell_exec($command);
        $this->assertNotNull($output, sprintf('Fixture runner (%s) produced no output.', $action));

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
            static fn(string $line): bool => trim($line) !== '',
        ));
        $payload = json_decode($lines !== [] ? $lines[count($lines) - 1] : '', true);
        $this->assertIsArray($payload, sprintf('Fixture runner (%s) returned invalid JSON: %s', $action, $output));

        return $payload;
    }

    private function buildConfigFile(): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'testing',
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Test'],
                'ssr' => [
                    'theme' => '',
                    'cache_max_age' => 0,
                ],
            ];
            PHP;
    }

    private function initializeFreshDatabase(): void
    {
        $result = $this->runFixture('db-init');

        $this->assertSame(
            0,
            (int) ($result['exit'] ?? 1),
            "Fresh db:init failed.\n" . (string) ($result['stderr'] ?? '') . (string) ($result['stdout'] ?? ''),
        );
        $this->assertStringContainsString('Created database', (string) ($result['stdout'] ?? ''));
    }

    private function copyDirectory(string $source, string $destination): void
    {
        new Filesystem()->mirror($source, $destination);
    }
}
