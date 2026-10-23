<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Task-owned J1 assertions; generic execution, HTTP and files use Symfony. */
final class PublishedS1Qualification
{
    private const BASE = '1545bad0790829e7673c155007833dbbbe3e45a3';
    private const RECIPIENT = 'SHA256:AZ4w6C+xBaNvGPBvgcd1J7U/hFMhAYMN24MrFpb4HXI';
    private const PRIVATE_VALUE = 'synthetic-j1-private-payload';
    private const ROUTES = <<<'PHP'
                $router->addRoute('j1.protected', RouteBuilder::create('/__j1/protected')->controller(static fn() => new Response('J1 protected'))->requireAuthentication()->priority(100)->methods('GET')->build());
                $router->addRoute('j1.failure', RouteBuilder::create('/__j1/failure')->controller(static fn() => throw new \RuntimeException('J1 controlled failure', 3199, new \LogicException('password=synthetic-j1-private-payload')))->allowAll()->priority(100)->methods('GET')->build());

        PHP;

    private Filesystem $fs;
    private string $repo;
    private string $version;
    private string $work;
    private string $evidence;
    private string $releaseSha = '';
    private string $latestPhp = '';
    private array $provenance = [];
    private array $steps = [];
    private array $containers = [];
    private array $failures = [];
    private array $secrets = [self::PRIVATE_VALUE];
    private float $deadline;
    private bool $finalizing = false;

    public function __construct()
    {
        $this->fs = new Filesystem();
        $this->repo = dirname(__DIR__, 2);
        $this->version = ltrim((string) getenv('J1_FRAMEWORK_VERSION'), 'v');
        $this->need(preg_match('/^0\.1\.0-alpha\.[0-9]+$/D', $this->version) === 1, 'Exact published alpha required');
        $run = (string) getenv('GITHUB_RUN_ID');
        $this->need(ctype_digit($run), 'Hosted run identity required');
        $this->work = getenv('RUNNER_TEMP') . '/j1-s1-' . $run;
        $this->evidence = (string) getenv('J1_EVIDENCE');
        $this->need(!file_exists($this->work) && !file_exists($this->evidence), 'Refuse reused qualification directories');
        $this->fs->mkdir([$this->work . '/raw', $this->work . '/apps', $this->evidence]);
        $started = (string) getenv('J1_JOB_STARTED_AT');
        $this->need(ctype_digit($started) && (int) $started <= time(), 'Workflow start identity required');
        $this->deadline = (int) $started + 30 * 60;
    }

    private function need(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function safe(string $data): string
    {
        foreach (glob($this->work . '/apps/*/.env*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            preg_match_all('/^WAASEYAA_APP_SECRET=["\']?([^\r\n"\']+)/m', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $secret) {
                $this->secrets[$secret] = $secret;
            }
        }

        foreach ($this->secrets as $secret) {
            $data = str_replace([$secret, substr(json_encode($secret, JSON_THROW_ON_ERROR), 1, -1)], '[REDACTED]', $data);
        }

        return $data;
    }

    private function save(string $name, mixed $data): void
    {
        $this->fs->dumpFile($this->evidence . '/' . $name, $this->safe(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
    }

    private function retain(string $name, string $data): void
    {
        $this->fs->dumpFile($this->work . '/raw/' . $name, $data);
        $this->fs->dumpFile($this->evidence . '/' . $name, $this->safe($data));
    }

    private function run(string $label, array $argv, ?string $cwd = null, bool $check = true, int $timeout = 300): array
    {
        $remaining = $this->deadline + ($this->finalizing ? 15 * 60 : 0) - microtime(true);
        $this->need($remaining > 0, 'Qualification/custody deadline reached');
        $process = new Process($argv, $cwd, timeout: min($timeout, $remaining));
        $start = microtime(true);
        try {
            $code = $process->run();
        } catch (ProcessTimedOutException) {
            $code = 124;
        }
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->retain($label . '.stdout', $out);
        $this->retain($label . '.stderr', $err);
        $this->steps[] = ['label' => $label, 'argv' => $argv, 'cwd' => $cwd, 'exit' => $code, 'seconds' => round(microtime(true) - $start, 3)];
        $this->save('steps.json', $this->steps);
        echo $label, ' ', $code, "\n";
        $this->need(!$check || $code === 0, $label . ' exited ' . $code);

        return [$code, $out, $err];
    }

    private function name(string $profile): string
    {
        return 'j1-s1-' . $profile . '-' . getenv('GITHUB_RUN_ID');
    }

    private function command(string $profile, string $label, array $args, bool $root = true, bool $check = true): array
    {
        return $this->run($profile . '-' . $label, ['docker', 'exec', '--workdir', $root ? '/work/' . $profile : '/work',
            '--user', 'www-data', '--env', 'COMPOSER_HOME=/work/composer-home-' . $profile, $this->name($profile), ...$args], check: $check, timeout: 600);
    }

    private function cli(string $profile, string $label, array $args, bool $check = true): array
    {
        return $this->command($profile, $label, ['php', 'vendor/bin/waaseyaa', ...$args, '--no-interaction', '--no-ansi'], check: $check);
    }

    private function fetch(string $url): string
    {
        $response = HttpClient::create(['timeout' => 15, 'max_duration' => 30])->request('GET', $url);
        $this->need($response->getStatusCode() === 200, 'Metadata HTTP refusal: ' . $url);

        return $response->getContent();
    }

    private function admission(): void
    {
        [, $os] = $this->run('host-os', ['cat', '/etc/os-release']);
        $this->need(str_contains($os, 'ID=ubuntu') && str_contains($os, 'VERSION_ID="24.04"'), 'Unsupported native host OS');
        [, $kernel] = $this->run('host-kernel', ['uname', '-srm']);
        $this->need(str_contains($kernel, 'x86_64') && !str_contains(strtolower($kernel), 'microsoft'), 'Unsupported native host kernel');
        [, $mount] = $this->run('host-mount', ['findmnt', '--target', $this->work . '/apps', '--json', '--output', 'SOURCE,FSTYPE,OPTIONS']);
        $this->need(json_decode($mount, true, flags: JSON_THROW_ON_ERROR)['filesystems'][0]['fstype'] === 'ext4', 'App storage is not local ext4');
        foreach (['dev', 'no-dev'] as $profile) {
            [$code, , $error] = $this->run($profile . '-container-admission', ['docker', 'container', 'inspect', $this->name($profile)], check: false);
            $this->need($code === 1 && preg_match('/No such (?:object|container):/', $error) === 1, 'Container name is not confirmed absent: ' . $profile);
        }
        [, $harness] = $this->run('harness-revision', [$this->repo . '/bin/git', 'rev-parse', 'HEAD'], $this->repo);
        $this->save('harness-identity.json', ['sha' => trim($harness), 'script_sha256' => hash_file('sha256', __FILE__)]);
        $recipient = $this->work . '/recipient.pub';
        $recipientLine = trim((string) getenv('J1_EVIDENCE_RECIPIENT'));
        $this->need(preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+(?: [^\r\n]*)?$/D', $recipientLine) === 1, 'Exactly one SSH public recipient is required');
        $this->fs->dumpFile($recipient, $recipientLine . "\n");
        [, $fingerprint] = $this->run('recipient-identity', ['ssh-keygen', '-lf', $recipient]);
        $this->need((preg_split('/\s+/', trim($fingerprint))[1] ?? '') === self::RECIPIENT, 'Recipient differs from locally verified age identity');
        $this->run('snapshot-tool-version', ['age', '--version']);
        $release = $this->work . '/release';
        $this->fs->mkdir($release);
        $this->run('release-assets', ['gh', 'release', 'download', 'v' . $this->version, '--repo', 'waaseyaa/framework', '--pattern', 'SHA256SUMS',
            '--pattern', 'waaseyaa-framework-provenance.json', '--pattern', 'waaseyaa-framework.cdx.json', '--dir', $release]);
        foreach (file($release . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$hash, $name] = preg_split('/\s+/', $line, 2);
            $this->need(in_array($name, ['waaseyaa-framework-provenance.json', 'waaseyaa-framework.cdx.json'], true), 'Unexpected checksum target');
            $this->need(hash_file('sha256', $release . '/' . $name) === $hash, 'Release asset checksum mismatch');
        }
        $this->provenance = json_decode(file_get_contents($release . '/waaseyaa-framework-provenance.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->releaseSha = $this->provenance['source']['sha'];
        $this->need($this->provenance['source']['repository'] === 'waaseyaa/framework', 'Wrong release repository');
        $this->run('release-ancestry', [$this->repo . '/bin/git', 'merge-base', '--is-ancestor', self::BASE, $this->releaseSha], $this->repo);
        [, $tag] = $this->run('release-tag', [$this->repo . '/bin/git', 'rev-parse', 'v' . $this->version . '^{commit}'], $this->repo);
        $this->need(trim($tag) === $this->releaseSha, 'Tag/provenance identity mismatch');
        foreach (glob($release . '/*') as $path) {
            $this->fs->copy($path, $this->evidence . '/' . basename($path));
        }
        $latest = json_decode($this->fetch('https://www.php.net/releases/index.php?json&version=8.5&max=1'), true, flags: JSON_THROW_ON_ERROR);
        $this->latestPhp = $latest['version'] ?? (string) array_key_first($latest);
        $this->need(preg_match('/^8\.5\.[0-9]+$/D', $this->latestPhp) === 1, 'Unrecognized upstream PHP8.5 identity');
        $this->save('upstream-php.json', $latest);
        $build = $this->work . '/build';
        $this->fs->mkdir($build);
        $this->fs->copy(__DIR__ . '/Dockerfile', $build . '/Dockerfile');
        $this->fs->copy((string) getenv('J1_COMPOSER_PHAR'), $build . '/composer.phar');
        $this->save('build-inputs.json', ['Dockerfile' => hash_file('sha256', $build . '/Dockerfile'), 'composer.phar' => hash_file('sha256', $build . '/composer.phar')]);
        $this->run('build-runtime', ['docker', 'build', '--pull', '--tag', 'j1-s1:' . getenv('GITHUB_RUN_ID'), $build], timeout: 900);
        $this->run('image-inspect', ['docker', 'image', 'inspect', 'j1-s1:' . getenv('GITHUB_RUN_ID')]);
    }

    private function zipMatches(string $zipPath, string $installed, array $required = []): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $seen = [];
        $prefix = explode('/', $zip->getNameIndex(0))[0];
        if (preg_match('/^[A-Za-z0-9._-]+-[a-f0-9]{7,40}$/D', $prefix) !== 1) {
            $zip->close();

            return false;
        }
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = $zip->getNameIndex($i);
            if (!str_starts_with($entry, $prefix . '/') || str_contains($entry, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)~', $entry)) {
                $zip->close();

                return false;
            }
            $relative = substr($entry, strpos($entry, '/') + 1);
            if ($relative === '' || str_ends_with($relative, '/') || ($required !== [] && !in_array($relative, $required, true))) {
                continue;
            }
            $path = $installed . '/' . $relative;
            if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== hash('sha256', $zip->getFromIndex($i))) {
                $zip->close();

                return false;
            }
            $seen[] = $relative;
        }
        $zip->close();

        return $seen !== [] && ($required === [] || array_diff($required, $seen) === []);
    }

    private function cacheIdentity(string $profile, string $package, string $installed, array $required = []): array
    {
        foreach (glob($this->work . '/apps/composer-home-' . $profile . '/cache/files/' . $package . '/*.zip') ?: [] as $archive) {
            if ($this->zipMatches($archive, $installed, $required)) {
                $zip = new ZipArchive();
                $zip->open($archive);
                $archiveRoot = explode('/', $zip->getNameIndex(0))[0];
                $zip->close();

                return ['archive' => basename($archive), 'sha256' => hash_file('sha256', $archive), 'archive_root' => $archiveRoot, 'installed_bytes_match' => true];
            }
        }
        throw new RuntimeException('No original Composer dist archive matches installed ' . $package);
    }

    private function cohort(string $profile): void
    {
        $app = $this->work . '/apps/' . $profile;
        $lock = json_decode(file_get_contents($app . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents($app . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['repositories'] ?? [] as $repository) {
            $this->need(!is_array($repository) || ($repository['type'] ?? '') !== 'path', 'Consumer path repository');
        }
        $expected = array_column($this->provenance['packages'], 'split_sha', 'name');
        $expected['waaseyaa/framework'] = $this->releaseSha;
        $members = [];
        foreach (array_merge($lock['packages'], $profile === 'dev' ? $lock['packages-dev'] : []) as $package) {
            if (!str_starts_with($package['name'], 'waaseyaa/')) {
                continue;
            }
            $name = $package['name'];
            $this->need(ltrim($package['version'], 'v') === $this->version, 'Mixed first-party versions: ' . $name);
            $this->need(isset($expected[$name]) && $package['source']['reference'] === $expected[$name]
                && $package['dist']['reference'] === $expected[$name] && $package['dist']['type'] !== 'path', 'Published reference mismatch: ' . $name);
            $installed = $app . '/vendor/' . $name;
            $this->need(!is_link($installed), 'Symlinked consumer package: ' . $name);
            $members[] = ['package' => $package, 'archive' => ($package['type'] ?? '') === 'metapackage' ? null : $this->cacheIdentity($profile, $name, $installed)];
        }
        $this->need(count($members) > 1, 'Missing installed first-party cohort');
        $this->save($profile . '-cohort.json', ['lock_sha256' => hash_file('sha256', $app . '/composer.lock'), 'members' => $members]);
        $this->save($profile . '-template-archive.json', $this->cacheIdentity($profile, 'waaseyaa/waaseyaa', $app, ['public/index.php', 'composer.json']));
        $this->command($profile, 'installed-root', ['php', '/usr/local/bin/composer', 'show', '--self', '--format=json']);
        $this->command($profile, 'installed-packages', ['php', '/usr/local/bin/composer', 'show', '--format=json']);
    }

    private function configure(string $profile): void
    {
        $app = $this->work . '/apps/' . $profile;
        $this->fs->dumpFile($app . '/.env.local', "APP_ENV=production\nAPP_DEBUG=false\nWAASEYAA_DEV_FALLBACK_ACCOUNT=false\nAPP_ORIGIN=http://127.0.0.1:19229\nAPP_URL=http://127.0.0.1:19229\n");
        $page = $app . '/templates/home.html.twig';
        $this->fs->dumpFile($page, "<!-- FW-J1-PUBLISHED-S1-01 {{ 6 * 7 }} -->\n" . file_get_contents($page));
        $provider = $app . '/src/Provider/AppServiceProvider.php';
        $text = file_get_contents($provider);
        $needle = '        $router->addRoute(';
        $this->need(substr_count($text, $needle) === 1, 'Unexpected application route seam');
        $this->fs->dumpFile($provider, str_replace($needle, self::ROUTES . $needle, $text));
        $this->fs->copy(__DIR__ . '/runtime.php', $app . '/public/__j1_runtime.php');
        $this->fs->copy(__DIR__ . '/read-content.php', $app . '/j1-read-content.php');
        $this->fs->dumpFile($app . '/minimal.seed.yaml', "schema: waaseyaa.site-seed\nversion: 1\napplication:\n  id: j1published\n  name: J1 Published\n  canonical_origin:\n    config_key: APP_ORIGIN\ncontent_types:\n  - id: page\n    canonical_route: /{slug}\n");
        $this->run($profile . '-input-owner', ['chown', '-R', '33:33', $app]);
    }

    private function request(string $profile, string $label, string $path): array
    {
        $remaining = $this->deadline - microtime(true);
        $this->need($remaining > 0, 'Qualification deadline reached; custody time reserved');
        $response = HttpClient::create(['timeout' => min(10, $remaining), 'max_duration' => min(15, $remaining), 'max_redirects' => 0])->request(
            'GET',
            'http://127.0.0.1:19229' . $path,
            ['headers' => ['Accept' => 'application/vnd.api+json']],
        );
        $status = $response->getStatusCode();
        $body = $response->getContent(false);
        $this->retain($profile . '-' . $label . '.body', $body);
        $this->save($profile . '-' . $label . '.http.json', ['status' => $status, 'headers' => $response->getHeaders(false), 'body_sha256' => hash('sha256', $body)]);

        return [$status, $body];
    }

    private function service(string $profile, string $label, bool $start = true): array
    {
        if (!$start) {
            $pids = $this->run($profile . '-' . $label . '-pids', ['docker', 'exec', $this->name($profile), 'bash', '-euc', 'cat /run/apache2/apache2.pid; pgrep -x php-fpm8.5']);
            $this->run($profile . '-' . $label . '-stop', ['docker', 'exec', $this->name($profile), 'bash', '-euc', 'apache2ctl -k stop; pkill -TERM -x php-fpm8.5']);
            usleep(1000000);

            return $pids;
        }
        $this->run($profile . '-' . $label . '-start-fpm', ['docker', 'exec', '-d', $this->name($profile), 'php-fpm8.5', '-F']);
        $this->run($profile . '-' . $label . '-start-apache', ['docker', 'exec', '-d', $this->name($profile), 'apache2ctl', '-D', 'FOREGROUND']);
        for ($attempt = 0; $attempt < 40; ++$attempt) {
            try {
                [$status, $body] = $this->request($profile, $label . '-readiness-' . $attempt, '/__j1_runtime.php');
                $witness = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                if ($status === 200 && $witness['sapi'] === 'fpm-fcgi') {
                    $this->save($profile . '-' . $label . '-fpm-witness.json', $witness);

                    return $witness;
                }
            } catch (Throwable $failure) {
                // Only the isolated runtime witness may be polled; home is requested once.
                if (microtime(true) >= $this->deadline) {
                    throw $failure;
                }
            }
            usleep(250000);
        }
        throw new RuntimeException('Apache/FPM readiness timeout: ' . $profile . '/' . $label);
    }

    private function home(string $profile, string $label): void
    {
        [$status, $body] = $this->request($profile, $label, '/');
        $this->need($status === 200 && str_contains($body, 'FW-J1-PUBLISHED-S1-01 42') && !str_contains($body, '{{ 6 * 7 }}'), $profile . ' homepage boundary: ' . $label);
    }

    private function profile(string $profile): void
    {
        $name = $this->name($profile);
        $this->containers[] = $name;
        $this->run($profile . '-container-start', ['docker', 'run', '-d', '--name', $name, '--memory', '2g', '--cpus', '2',
            '--env', 'APP_ENV=production', '--env', 'APP_DEBUG=false', '--env', 'WAASEYAA_DEV_FALLBACK_ACCOUNT=false',
            '-p', '127.0.0.1:19229:80', '--mount', 'type=bind,src=' . $this->work . '/apps,dst=/work', 'j1-s1:' . getenv('GITHUB_RUN_ID')]);
        $this->run($profile . '-workspace-owner', ['docker', 'exec', $name, 'bash', '-euc', 'mkdir /work/composer-home-' . $profile . '; chown www-data:www-data /work /work/composer-home-' . $profile]);
        [, $runtime] = $this->run($profile . '-runtime', ['docker', 'exec', $name, 'bash', '-euc', 'cat /etc/os-release; uname -srm; php -v; php /usr/local/bin/composer --version; apache2ctl -V; php-fpm8.5 -v; cat /opt/runtime-packages.txt']);
        $this->need(str_contains($runtime, 'VERSION_ID="24.04"') && str_contains($runtime, 'Apache/2.4.')
            && preg_match('/Composer version 2\.10\.[0-9]+/', $runtime) === 1, 'Serving OS/Apache/Composer tuple mismatch');
        [, $version] = $this->run($profile . '-php-version', ['docker', 'exec', $name, 'php', '-r', 'echo PHP_VERSION;']);
        $this->need($version === $this->latestPhp, 'Image is not latest available upstream PHP8.5 patch');
        $create = ['php', '/usr/local/bin/composer', 'create-project', 'waaseyaa/waaseyaa', $profile, '--stability=dev', '--no-interaction', '--no-ansi'];
        if ($profile === 'no-dev') {
            $create[] = '--no-dev';
        }
        $before = $this->fetch('https://repo.packagist.org/p2/waaseyaa/waaseyaa~dev.json');
        $this->retain($profile . '-template-before.json', $before);
        $this->retain($profile . '-template-tagged-metadata.json', $this->fetch('https://repo.packagist.org/p2/waaseyaa/waaseyaa.json'));
        [, $createdOut, $createdErr] = $this->command($profile, 'create-project', $create, root: false);
        $after = $this->fetch('https://repo.packagist.org/p2/waaseyaa/waaseyaa~dev.json');
        $this->retain($profile . '-template-after.json', $after);
        $this->need($before === $after, 'Template metadata moved during create-project');
        $this->need(preg_match('/Installing waaseyaa\/waaseyaa \(([^)]+)\)/', $createdOut . $createdErr, $templateVersion) === 1, 'Template installer identity absent');
        $selectedVersion = explode(' ', $templateVersion[1])[0];
        $this->need(preg_match('/^(?:dev-main|v?0\.1\.0-alpha\.[0-9]+)$/D', $selectedVersion) === 1, 'Unexpected template version');
        [, $templateMetadata] = $this->command($profile, 'selected-template-metadata', ['php', '/usr/local/bin/composer', 'show', 'waaseyaa/waaseyaa', $selectedVersion, '--all', '--format=json']);
        $templateMetadata = json_decode($templateMetadata, true, flags: JSON_THROW_ON_ERROR);
        $app = $this->work . '/apps/' . $profile;
        $templateArchive = $this->cacheIdentity($profile, 'waaseyaa/waaseyaa', $app, ['public/index.php', 'composer.json']);
        $this->need(($templateMetadata['source']['reference'] ?? null) === ($templateMetadata['dist']['reference'] ?? null)
            && isset($templateMetadata['source']['reference'])
            && str_ends_with($templateArchive['archive_root'], substr($templateMetadata['source']['reference'], 0, 7)), 'Template source/dist/archive identity mismatch');
        $this->save($profile . '-selected-template.json', ['version' => $selectedVersion, 'metadata' => $templateMetadata, 'archive' => $templateArchive]);
        $this->fs->copy($app . '/composer.lock', $this->evidence . '/' . $profile . '-bootstrap.lock');
        if ($profile === 'no-dev') {
            $this->need(is_file($this->work . '/apps/dev/composer.lock'), 'No dev lock for no-dev comparison');
            $this->fs->copy($this->work . '/apps/dev/composer.lock', $app . '/composer.lock', true);
        }
        $this->command($profile, 'locked-install', ['php', '/usr/local/bin/composer', 'install', '--no-interaction', '--no-ansi', ...($profile === 'no-dev' ? ['--no-dev'] : [])]);
        $this->command($profile, 'platform', ['php', '/usr/local/bin/composer', 'check-platform-reqs', '--no-interaction', '--no-ansi']);
        $this->cohort($profile);
        $this->configure($profile);
        $this->cli($profile, 'site-init', ['site:init', '--preset=minimal', '--answers=minimal.seed.yaml', '--yes']);
        $this->need(!file_exists($app . '/storage/waaseyaa.sqlite'), 'site:init created database');
        $this->cli($profile, 'install-init', ['install:init']);
        if ($profile === 'dev') {
            $this->command($profile, 'site-verify', ['php', '/usr/local/bin/composer', 'site-verify', '--no-interaction', '--no-ansi']);
        } else {
            $this->need(!is_file($app . '/vendor/bin/phpunit'), 'No-dev installation contains the development verifier');
            $this->cli($profile, 'site-doctor', ['site:doctor', '--strict', '--format=json']);
        }
        $artifact = $app . '/.waaseyaa/field-access-preflight.json';
        $this->need(!file_exists($artifact), 'Installer produced field access artifact');
        [$code, $scan] = $this->cli($profile, 'readonly-preflight', ['field-access:preflight', '--format=json'], false);
        $result = json_decode($scan, true, flags: JSON_THROW_ON_ERROR);
        $this->save($profile . '-names-only-result.json', $result);
        $this->need($code === 0 && $result['ready'] === true && !file_exists($artifact), 'Read-only field preflight is not ready or wrote artifact');
        $this->cli($profile, 'write-artifact', ['field-access:preflight', '--format=json', '--write-artifact']);
        $activation = file_get_contents($artifact);
        $this->need(json_decode($activation, true, flags: JSON_THROW_ON_ERROR) === $result, 'Artifact differs from canonical scan');
        $this->fs->copy($artifact, $this->evidence . '/' . $profile . '-activation.json');
        $this->cli($profile, 'ordinary-cli', ['about']);
        [, $created] = $this->cli($profile, 'content-create', ['entity:create', 'node', '--field=type=page', '--field=title=J1-canonical-preservation-witness',
            '--field=slug=j1-canonical-preservation-witness', '--field=body=J1-canonical-body', '--field=summary=J1-canonical-summary', '--field=status=1']);
        $this->need(preg_match('/Created node entity with ID: (\S+)/', $created, $id) === 1, 'Missing created entity identity');
        [, $beforeContent] = $this->command($profile, 'content-before', ['php', 'j1-read-content.php', $id[1]]);
        [, $beforeList] = $this->cli($profile, 'list-before', ['entity:list', 'node', '--limit=100']);
        $this->cli($profile, 'install-repeat', ['install:init']);
        [, $afterContent] = $this->command($profile, 'content-after', ['php', 'j1-read-content.php', $id[1]]);
        [, $afterList] = $this->cli($profile, 'list-after', ['entity:list', 'node', '--limit=100']);
        $this->need($beforeContent === $afterContent && $beforeList === $afterList && substr_count($afterList, 'J1-canonical-preservation-witness') === 1
            && $activation === file_get_contents($artifact), 'Repeat changed canonical content or field activation');
        $config = $this->work . '/' . $profile . '-apache.conf';
        $this->fs->dumpFile($config, "ServerName j1-s1.local\n<VirtualHost *:80>\nDocumentRoot /work/$profile/public\n<Directory /work/$profile/public>\nAllowOverride None\nOptions -Indexes\nRequire all granted\nDirectoryIndex index.php\nFallbackResource /index.php\n</Directory>\nErrorLog /var/log/apache2/j1-error.log\nCustomLog /var/log/apache2/j1-access.log combined\n</VirtualHost>\n");
        $this->run($profile . '-apache-config', ['docker', 'cp', $config, $name . ':/etc/apache2/sites-available/j1.conf']);
        $this->run($profile . '-apache-validate', ['docker', 'exec', $name, 'bash', '-euc', 'a2ensite j1; apache2ctl -t; php-fpm8.5 -t']);
        $witness = $this->service($profile, 'cold');
        $this->need($witness['php'] === $this->latestPhp && $witness['environment'] === 'production' && $witness['debug'] === 'false'
            && $witness['fallback'] === 'false' && $witness['secret_present'] === true && $witness['phpunit_present'] === ($profile === 'dev')
            && version_compare($witness['sqlite']['versionString'], '3.40.0', '>=') && version_compare($witness['sqlite']['versionString'], '4.0.0', '<'), 'FPM S1 composition mismatch');
        $this->home($profile, 'home-cold');
        $this->home($profile, 'home-warm');
        [, $oldPids] = $this->service($profile, 'restart-before', false);
        $this->service($profile, 'restart');
        [, $newPids] = $this->run($profile . '-restart-after-pids', ['docker', 'exec', $name, 'bash', '-euc', 'cat /run/apache2/apache2.pid; pgrep -x php-fpm8.5']);
        $oldPids = explode("\n", trim($oldPids));
        $newPids = explode("\n", trim($newPids));
        $this->need(count($oldPids) >= 2 && count($newPids) >= 2 && $oldPids[0] !== $newPids[0]
            && $oldPids[1] !== $newPids[1], 'Apache and FPM master processes did not both restart');
        $this->home($profile, 'home-restarted');
        $this->refusals($profile, $activation);
        $this->save($profile . '-result.json', ['result' => 'PASS', 'lock_sha256' => hash_file('sha256', $app . '/composer.lock'), 'artifact_sha256' => hash_file('sha256', $artifact)]);
    }

    private function controllerEvents(string $log): array
    {
        $events = [];
        $marker = '[critical] [default] Unhandled controller exception. ';
        foreach (explode("\n", $log) as $line) {
            // Apache may combine messages; TextFormatter puts only context in JSON.
            for ($offset = 0; ($offset = strpos($line, $marker, $offset)) !== false; $offset += strlen($marker)) {
                $candidate = substr($line, $offset + strlen($marker));
                for ($end = strlen($candidate); $end > 0; --$end) {
                    if ($candidate[$end - 1] !== '}') {
                        continue;
                    }
                    $context = json_decode(substr($candidate, 0, $end), true);
                    if (is_array($context)) {
                        $events[] = $context;
                        break;
                    }
                }
            }
        }

        return $events;
    }

    private function refusals(string $profile, string $activation): void
    {
        foreach ([['generated404', '/__j1_absent_71a0', 404], ['unmatched404', '/__j1/absent/multiple/segments', 404],
            ['stranger', '/__j1/protected', 401], ['controlled500', '/__j1/failure', 500]] as [$label, $path, $expected]) {
            [$status, $body] = $this->request($profile, $label, $path);
            $this->need($status === $expected && !str_contains($body, '/work/') && !str_contains($body, self::PRIVATE_VALUE), $label . ' HTTP/privacy boundary');
            if ($expected === 500) {
                $this->need(str_contains($body, 'An unexpected error occurred.') && !str_contains($body, 'RuntimeException') && !str_contains($body, 'J1 controlled failure'), 'Public 500 not generic');
            }
        }
        [, $log] = $this->run($profile . '-private-log', ['docker', 'exec', $this->name($profile), 'cat', '/var/log/apache2/j1-error.log']);
        $events = $this->controllerEvents($log);
        $json = json_encode($events, JSON_THROW_ON_ERROR);
        $this->need($events !== [] && str_contains($json, '"type":"RuntimeException"') && str_contains($json, '"code":3199')
            && str_contains($json, '"message":"J1 controlled failure"') && str_contains($json, '"type":"LogicException"')
            && str_contains($json, '"message":"[REDACTED]"') && !str_contains($log, self::PRIVATE_VALUE), 'Private exception/redaction boundary');
        $this->save($profile . '-diagnostic-events.json', $events);
        $app = $this->work . '/apps/' . $profile;
        $env = $app . '/.env.local';
        $original = file_get_contents($env);
        $this->fs->dumpFile($this->work . '/restoration/' . $profile . '.env.local', $original);
        $artifact = $app . '/.waaseyaa/field-access-preflight.json';
        $this->save($profile . '-restoration-inputs.json', ['environment_sha256' => hash('sha256', $original), 'activation_sha256' => hash('sha256', $activation)]);
        foreach ([['missing-secret', "WAASEYAA_APP_SECRET=\n", 'WAASEYAA_APP_SECRET'],
            ['missing-database', "WAASEYAA_DB=/work/$profile/absent/db.sqlite\n", 'Database not found']] as [$label, $setting, $diagnostic]) {
            $this->service($profile, $label, false);
            $this->fs->dumpFile($env, $original . $setting);
            [$code, $out, $err] = $this->cli($profile, $label . '-cli', ['about'], false);
            $this->need($code === 1 && str_contains($out . $err, $diagnostic), $label . ' CLI refusal');
            $this->service($profile, $label);
            [$status, $body] = $this->request($profile, $label, '/');
            $this->need($status === 500 && !str_contains($body, '/work/') && !str_contains($body, 'WAASEYAA_APP_SECRET'), $label . ' HTTP refusal');
            $this->service($profile, $label . '-restore', false);
            $this->fs->dumpFile($env, $original);
            $this->need(file_get_contents($artifact) === $activation && !file_exists($app . '/absent/db.sqlite'), 'Refusal changed artifact/created DB');
            $this->service($profile, $label . '-recovery');
            $this->home($profile, $label . '-recovered');
        }
        $this->service($profile, 'missing-artifact', false);
        $this->fs->rename($artifact, $artifact . '.held');
        [$code] = $this->cli($profile, 'missing-artifact-cli', ['about'], false);
        $this->need($code === 1, 'Ordinary CLI accepted missing artifact');
        $this->service($profile, 'missing-artifact');
        [$status] = $this->request($profile, 'missing-artifact-http', '/');
        $this->need($status === 500, 'Ordinary HTTP accepted missing artifact');
        $this->service($profile, 'missing-artifact-restore', false);
        $this->fs->rename($artifact . '.held', $artifact);
        $this->need(file_get_contents($artifact) === $activation, 'Restored artifact differs');
        $this->service($profile, 'artifact-recovery');
        $this->home($profile, 'artifact-recovered');
        $this->run($profile . '-negative-ini', ['docker', 'exec', $this->name($profile), 'bash', '-euc',
            'mkdir /tmp/j1-ini; for f in /etc/php/8.5/cli/conf.d/*.ini; do case "$f" in *sqlite*) ;; *) cp "$f" /tmp/j1-ini/ ;; esac; done']);
        [$code, $out, $err] = $this->run($profile . '-platform-negative', ['docker', 'exec', '--workdir', '/work/' . $profile,
            '--env', 'PHP_INI_SCAN_DIR=/tmp/j1-ini', $this->name($profile), 'php', '/usr/local/bin/composer', 'check-platform-reqs', '--lock', '--no-interaction', '--no-ansi'], check: false);
        $this->need($code !== 0 && str_contains($out . $err, 'ext-pdo_sqlite') && str_contains($out . $err, 'missing'), 'Missing platform extension did not refuse clearly');
    }

    private function freeze(string $profile): void
    {
        if (!in_array($this->name($profile), $this->containers, true)) {
            return;
        }
        $this->finalizing = true;
        try {
            [$code, , $error] = $this->run($profile . '-possibly-created-inspect', ['docker', 'container', 'inspect', $this->name($profile)], check: false);
            if ($code !== 0) {
                $this->need($code === 1 && preg_match('/No such (?:object|container):/', $error) === 1, 'Cannot establish possibly-created container custody: ' . $profile);
                $this->save($profile . '-container-custody.json', ['name' => $this->name($profile), 'state' => 'confirmed not created']);

                return;
            }
            $this->run($profile . '-stop-container', ['docker', 'stop', '--time', '1', $this->name($profile)], timeout: 30);
            $this->run($profile . '-container-inspect', ['docker', 'inspect', $this->name($profile)]);
            $this->run($profile . '-container-export', ['docker', 'export', '--output', $this->work . '/' . $profile . '-container.tar', $this->name($profile)]);
        } finally {
            $this->finalizing = false;
        }
    }

    private function seal(): void
    {
        $this->finalizing = true;
        $archive = $this->work . '.tar.gz';
        $this->run('snapshot', ['tar', '--numeric-owner', '--acls', '--xattrs', '-czf', $archive, '-C', $this->work, '.'], timeout: 900);
        $encrypted = $this->evidence . '/applications-and-runtime.tar.gz.age';
        $this->run('encrypt-snapshot', ['age', '-R', $this->work . '/recipient.pub', '-o', $encrypted, $archive], timeout: 300);
        $this->save('snapshot.json', ['plaintext_sha256' => hash_file('sha256', $archive), 'ciphertext_sha256' => hash_file('sha256', $encrypted),
            'recipient' => self::RECIPIENT, 'scope' => 'complete bind app/env/DB/WAL/SHM/vendor, raw streams, stopped rootfs/inspect, release/build inputs',
            'lifetime' => 'ephemeral hosted VM; restorable bytes retained, not continued VM/container identity']);
        $this->fs->remove($archive);
    }

    public function execute(): int
    {
        try {
            $this->admission();
            foreach (['dev', 'no-dev'] as $profile) {
                try {
                    $this->profile($profile);
                } catch (Throwable $failure) {
                    $this->failures[] = ['profile' => $profile, 'first_failure' => $failure->getMessage()];
                    $this->save($profile . '-first-failure.json', end($this->failures));
                    echo $profile, ' first failure retained', "\n";
                } finally {
                    $this->freeze($profile);
                }
            }
        } catch (Throwable $failure) {
            $this->failures[] = ['profile' => 'admission-or-custody', 'first_failure' => $failure->getMessage()];
            $this->save('admission-first-failure.json', end($this->failures));
        } finally {
            $custody = 'incomplete';
            try {
                $this->seal();
                $custody = 'sealed; local download/hash/decryption verification still required';
            } catch (Throwable $failure) {
                $this->failures[] = ['profile' => 'custody', 'first_failure' => $failure->getMessage()];
                $this->save('custody-first-failure.json', end($this->failures));
            }
            $this->save('result.json', ['version' => 'v' . $this->version, 'release_sha' => $this->releaseSha, 'result' => $this->failures === [] ? 'PASS' : 'FAIL',
                'failures' => $this->failures, 'custody' => $custody, 's1' => 'observed J1 tuple only; global S0-SHEG-02 certification remains pending']);
        }

        return $this->failures === [] ? 0 : 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(new PublishedS1Qualification()->execute());
}
