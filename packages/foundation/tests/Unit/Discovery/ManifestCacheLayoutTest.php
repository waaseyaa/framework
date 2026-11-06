<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\{ManifestCacheLayout, ManifestCompilationContext, PackageManifestCompiler};
use Waaseyaa\Foundation\Kernel\{HttpKernel, ConsoleKernel};
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

final class ManifestCacheLayoutTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/manifest-layout-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700);
    }
    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        (new Filesystem())->remove($this->root);
    }
    private function release(string $name = 'release', string $digest = ''): string
    {
        $digest = $digest ?: str_repeat('a', 64);
        $base = $this->root . '/' . $name;
        mkdir($base . '/.waaseyaa', 0o700, true);
        mkdir($base . '/vendor/composer', 0o700, true);
        if (!is_dir($this->root . '/runtime')) mkdir($this->root . '/runtime', 0o700);
        symlink($this->root . '/runtime', $base . '/storage');
        mkdir($this->root . '/runtime/framework/manifests/' . $digest, 0o700, true);
        file_put_contents($base . '/composer.json', '{"name":"local/manifest-fixture"}');
        file_put_contents($base . ManifestCacheLayout::LAYOUT_PATH, "artifact-bound-v1\n");
        file_put_contents($base . ManifestCacheLayout::BINDING_PATH, json_encode(['schema' => 'waaseyaa.manifest-runtime-binding.v1', 'artifact_sha256' => $digest], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        chmod($base . ManifestCacheLayout::BINDING_PATH, 0o440);
        file_put_contents($base . '/vendor/composer/installed.json', '{"packages":[]}');
        return $base;
    }
    public function testMarkerIsStrictBoundedRegularAndNeverChangesComposerLockOrRecords(): void
    {
        $base = $this->release();
        $marker = $base . ManifestCacheLayout::LAYOUT_PATH;
        file_put_contents($base . '/composer.lock', '{"content-hash":"synthetic-unchanged-lock"}');
        mkdir($base . '/docs/architecture', 0o700, true);
        file_put_contents($base . '/docs/architecture/framework-main-checkout.json', '{"fixture":"immutable-record"}');
        $inputs = ['/composer.json', '/composer.lock', '/docs/architecture/framework-main-checkout.json'];
        $before = array_map(static fn(string $path): string => (string) file_get_contents($base . $path), $inputs);
        foreach (["artifact-bound-v1", "artifact-bound-v1\r\n", "artifact-bound-v2\n", " artifact-bound-v1\n", '{"mode":"artifact-bound-v1"}', str_repeat('x', 1024)] as $invalid) {
            file_put_contents($marker, $invalid);
            try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Noncanonical marker accepted.'); }
            catch (\RuntimeException) { self::assertTrue(true); }
        }
        unlink($marker); file_put_contents($this->root . '/marker-target', "artifact-bound-v1\n"); symlink($this->root . '/marker-target', $marker);
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Marker symlink accepted.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
        unlink($marker); link($this->root . '/marker-target', $marker);
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Marker hardlink accepted.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
        unlink($marker); file_put_contents($marker, "artifact-bound-v1\n");
        new PackageManifestCompiler($base, $base . '/storage');
        self::assertSame($before, array_map(static fn(string $path): string => (string) file_get_contents($base . $path), $inputs));
    }
    public function testComposerMetadataIsNotASecondLayoutSelector(): void
    {
        $base = $this->release();
        unlink($base . ManifestCacheLayout::LAYOUT_PATH);
        unlink($base . ManifestCacheLayout::BINDING_PATH);
        file_put_contents($base . '/composer.json', '{"extra":{"waaseyaa":{"manifest-cache":"artifact-bound-v1"}}}');
        $compiler = new PackageManifestCompiler($base, $base . '/storage');
        $compiler->load();
        self::assertSame('legacy', $compiler->manifestCacheWitness()['context']);
        self::assertSame($base . '/storage/framework/packages.php', $compiler->selectedManifestPath());
    }    public function testBindingSelectsOnlyPreparedDigestRootAndPreservesOrdinaryStorage(): void
    {
        $base = $this->release();
        file_put_contents($this->root . '/runtime/waaseyaa.sqlite', 'database sentinel');
        file_put_contents($this->root . '/runtime/framework/packages.php', 'retained legacy sentinel');
        file_put_contents($base . '/.env', 'environment sentinel');
        $compiler = new PackageManifestCompiler($base, $base . '/storage');
        $compiler->load();
        $witness = $compiler->manifestCacheWitness();
        self::assertSame('artifact-bound', $witness['context']);
        self::assertSame(str_repeat('a', 64), $witness['artifact_sha256']);
        self::assertSame($this->root . '/runtime/framework/manifests/' . str_repeat('a', 64) . '/packages.php', $compiler->selectedManifestPath());
        self::assertSame(hash_file('sha256', $compiler->selectedManifestPath()), $witness['manifest_sha256']);
        self::assertSame('database sentinel', file_get_contents($this->root . '/runtime/waaseyaa.sqlite'));
        self::assertSame('retained legacy sentinel', file_get_contents($this->root . '/runtime/framework/packages.php'));
        self::assertSame('environment sentinel', file_get_contents($base . '/.env'));
    }
    public function testDifferentArtifactsNeverOverwriteEachOthersManifestOrCurrentSelection(): void
    {
        $a = $this->release('a', str_repeat('a', 64));
        $b = $this->release('b', str_repeat('b', 64));
        $first = new PackageManifestCompiler($a, $a . '/storage');
        $first->compileAndCache();
        $before = file_get_contents($first->selectedManifestPath());
        $second = new PackageManifestCompiler($b, $b . '/storage');
        $second->compileAndCache();
        self::assertNotSame($first->selectedManifestPath(), $second->selectedManifestPath());
        self::assertSame($before, file_get_contents($first->selectedManifestPath()));
        symlink($a, $this->root . '/current');
        unlink($this->root . '/current'); symlink($b, $this->root . '/current');
        $first->load();
        self::assertSame($a, $first->manifestCacheWitness()['physical_release_root']);
        self::assertSame($before, file_get_contents($first->selectedManifestPath()));
    }
    public function testPrivatePreflightCannotSilentlyWriteLiveCache(): void
    {
        $base = $this->release();
        mkdir($this->root . '/private/framework/manifests/' . str_repeat('a', 64), 0o700, true);
        try {
            new PackageManifestCompiler($base, $this->root . '/private');
            self::fail('Normal private-copy mismatch accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->root . '/runtime/framework/manifests/' . str_repeat('a', 64) . '/packages.php');
        }
        $isolated = new PackageManifestCompiler($base, $this->root . '/private', compilationContext: ManifestCompilationContext::IsolatedValidation);
        $isolated->load();
        self::assertSame('isolated-validation', $isolated->manifestCacheWitness()['context']);
        self::assertStringStartsWith($this->root . '/private/', $isolated->selectedManifestPath());
        self::assertFileDoesNotExist($this->root . '/runtime/framework/manifests/' . str_repeat('a', 64) . '/packages.php');
        $this->expectException(\RuntimeException::class);
        new PackageManifestCompiler($base, $base . '/storage', compilationContext: ManifestCompilationContext::IsolatedValidation);
    }
    public function testRequiredBindingAndPreparedRootRefuseMissingMalformedUnexpectedOrSymlink(): void
    {
        $base = $this->release();
        $path = $base . ManifestCacheLayout::BINDING_PATH;
        $valid = file_get_contents($path);
        foreach ([null, '{}', '{"schema":"waaseyaa.manifest-runtime-binding.v1","artifact_sha256":"bad"}', rtrim($valid), str_replace('artifact_sha256', 'unknown', $valid), str_replace(str_repeat('a', 64), str_repeat('A', 64), $valid)] as $invalid) {
            if (file_exists($path) || is_link($path)) unlink($path);
            if ($invalid !== null) file_put_contents($path, $invalid);
            try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Invalid binding accepted.'); }
            catch (\RuntimeException|\JsonException) { self::assertFileDoesNotExist($this->root . '/runtime/framework/manifests/' . str_repeat('a', 64) . '/packages.php'); }
        }
        unlink($path); file_put_contents($this->root . '/foreign-binding', $valid); symlink($this->root . '/foreign-binding', $path);
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Symlink binding accepted.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
        unlink($path); file_put_contents($path, $valid);
        $dir = $this->root . '/runtime/framework/manifests/' . str_repeat('a', 64);
        rmdir($dir); mkdir($this->root . '/foreign', 0o700); symlink($this->root . '/foreign', $dir);
        $this->expectException(\RuntimeException::class);
        new PackageManifestCompiler($base, $base . '/storage');
    }
    public function testLegacyWithoutOptInRetainsItsExplicitStorageAuthorityButRejectsBinding(): void
    {
        $base = $this->release();
        unlink($base . ManifestCacheLayout::LAYOUT_PATH);
        file_put_contents($base . '/composer.json', '{}');
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Unexpected binding accepted.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
        unlink($base . ManifestCacheLayout::BINDING_PATH);
        $compiler = new PackageManifestCompiler($base, $this->root . '/legacy-private');
        $compiler->load();
        self::assertSame($this->root . '/legacy-private/framework/packages.php', $compiler->selectedManifestPath());
        self::assertSame('legacy', $compiler->manifestCacheWitness()['context']);
    }
    public function testMissingRequiredBindingRefusesHttpAndConsoleBeforeDatabaseCreation(): void
    {
        $base = $this->release();
        unlink($base . ManifestCacheLayout::BINDING_PATH);
        foreach ([HttpKernel::class, ConsoleKernel::class] as $kernelClass) {
            try { new $kernelClass($base); self::fail('Kernel ignored missing required binding.'); }
            catch (\RuntimeException) { self::assertFileDoesNotExist($this->root . '/runtime/waaseyaa.sqlite'); }
        }
    }
    public function testPrivateStorageAliasCannotMasqueradeAsNormalBoundRuntime(): void
    {
        $base = $this->release();
        symlink($this->root . '/runtime', $this->root . '/private-alias');
        $this->expectException(\RuntimeException::class);
        new PackageManifestCompiler($base, $this->root . '/private-alias');
    }
    public function testDiagnosticReportsTheActualSelectedCacheDirectory(): void
    {
        $base = $this->release();
        $checker = new \Waaseyaa\Foundation\Diagnostic\HealthChecker(
            new \Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport([], [], []),
            $this->createStub(\Waaseyaa\Database\DatabaseInterface::class),
            $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class),
            $base,
        );
        $method = new \ReflectionMethod($checker, 'checkCacheDirectory');
        $result = $method->invoke($checker);
        self::assertStringContainsString('/framework/manifests/' . str_repeat('a', 64), $result->message);
    }    public function testMalformedMarkerMissingPreparationAndForeignCacheNeverAllocateOrFallback(): void
    {
        $base = $this->release();
        $marker = $base . ManifestCacheLayout::LAYOUT_PATH;
        file_put_contents($marker, "artifact-bound-v2\n");
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Unsupported marker accepted.'); }
        catch (\RuntimeException) { self::assertTrue(true); }
        file_put_contents($marker, "artifact-bound-v1\n");
        $dir = $this->root . '/runtime/framework/manifests/' . str_repeat('a', 64);
        rmdir($dir);
        try { new PackageManifestCompiler($base, $base . '/storage'); self::fail('Missing preparation allocated silently.'); }
        catch (\RuntimeException) { self::assertDirectoryDoesNotExist($dir); }
        mkdir($dir, 0o700);
        file_put_contents($dir . '/foreign', 'foreign sentinel');
        $this->expectException(\RuntimeException::class);
        new PackageManifestCompiler($base, $base . '/storage');
    }
    public function testTemporarySymlinkCannotRedirectCompilerWrites(): void
    {
        $base = $this->release();
        $compiler = new PackageManifestCompiler($base, $base . '/storage');
        file_put_contents($this->root . '/foreign', 'foreign sentinel');
        symlink($this->root . '/foreign', $compiler->selectedManifestPath() . '.tmp.' . getmypid());
        try { $compiler->compileAndCache(); self::fail('Pre-existing temporary symlink accepted.'); }
        catch (\RuntimeException) {
            self::assertSame('foreign sentinel', file_get_contents($this->root . '/foreign'));
            self::assertFileDoesNotExist($compiler->selectedManifestPath());
        }
    }
    public function testKnownMissingStampStaysWithinSelectedArtifact(): void
    {
        $base = $this->release();
        file_put_contents($base . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => ['UninstalledManifestLayoutTestProvider']]]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/runtime/framework/packages.php', 'retained legacy sentinel');
        $compiler = new PackageManifestCompiler($base, $base . '/storage');
        $compiler->load();
        $payload = require $compiler->selectedManifestPath();
        self::assertSame(['UninstalledManifestLayoutTestProvider'], $payload['_known_missing_providers']);
        self::assertSame('retained legacy sentinel', file_get_contents($this->root . '/runtime/framework/packages.php'));
        $before = file_get_contents($compiler->selectedManifestPath());
        new PackageManifestCompiler($base, $base . '/storage')->load();
        self::assertSame($before, file_get_contents($compiler->selectedManifestPath()));
    }
    public function testConcurrentDifferentLogicalManifestsRemainArtifactLocal(): void
    {
        if (!function_exists('pcntl_fork')) self::markTestSkipped('Concurrent compiler proof requires CLI pcntl.');
        $a = $this->release('a', str_repeat('a', 64));
        $b = $this->release('b', str_repeat('b', 64));
        file_put_contents($b . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Foundation\FoundationServiceProvider::class]]]], JSON_THROW_ON_ERROR));
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            try { new PackageManifestCompiler($b, $b . '/storage')->compileAndCache(); exit(0); }
            catch (\Throwable) { exit(1); }
        }
        $first = new PackageManifestCompiler($a, $a . '/storage');
        $first->compileAndCache();
        $before = file_get_contents($first->selectedManifestPath());
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        $second = new PackageManifestCompiler($b, $b . '/storage');
        $second->load();
        self::assertNotSame($first->manifestCacheWitness()['logical_manifest_sha256'], $second->manifestCacheWitness()['logical_manifest_sha256']);
        self::assertSame($before, file_get_contents($first->selectedManifestPath()));
    }    public function testRealHttpAndConsoleBootCaptureSameSelectedManifest(): void
    {
        $base = $this->release();
        mkdir($base . '/config', 0o700);
        file_put_contents($base . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing'];");
        file_put_contents($base . '/config/entity-types.php', '<?php return [new \Waaseyaa\Entity\EntityType(id: "test", label: "Test", class: \stdClass::class, keys: ["id" => "id"])];');
        $http = new HttpKernel($base);
        $httpWitness = $http->manifestCacheWitness();
        ProcessFieldReadRuntime::reset();
        $console = new ConsoleKernel($base);
        $consoleWitness = $console->manifestCacheWitness();
        self::assertSame($httpWitness['manifest_path'], $consoleWitness['manifest_path']);
        self::assertSame($httpWitness['logical_manifest_sha256'], $consoleWitness['logical_manifest_sha256']);
        self::assertSame('artifact-bound', $consoleWitness['context']);
    }
}
