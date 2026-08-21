<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2458 review finding 1.
 *
 * The packaged verified-config-import proof asserts that a consumer holds no
 * authoring custody. That assertion had two rules: an encoded-bytes scan and a
 * filename scan. Pruning `vendor/` from the filename scan — to tolerate the
 * public `signingkey*.asc` files `defuse/php-encryption` ships — silently
 * removed the only rule that could see a raw private key under `vendor/`,
 * because the bytes scan matches the key's **base64** form while the key file
 * itself holds raw `sodium_crypto_sign_secretkey()` bytes.
 *
 * These cases run the real detector against real trees, so the refusal rules
 * are proven rather than described.
 */
#[CoversNothing]
final class PackagedImportKeyHygieneTest extends TestCase
{
    private const string DETECTOR = 'tests/PackagedForm/check-consumer-key-hygiene';

    private const string DEFUSE_DIST = 'vendor/defuse/php-encryption/dist';

    private string $repoRoot;

    private string $work;

    private string $privateKeyFile;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('libsodium is required to mint a representative signing key.');
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $this->work = sys_get_temp_dir() . '/waaseyaa_key_hygiene_' . bin2hex(random_bytes(8));
        mkdir($this->work, 0o700, true);
        $this->privateKeyFile = $this->work . '/manifest-signing.key';
        // Exactly what the proof mints: raw secret-key bytes, not base64.
        file_put_contents(
            $this->privateKeyFile,
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()),
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->work)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->work);
    }

    #[Test]
    public function the_detector_exists_and_is_runnable(): void
    {
        $detector = $this->repoRoot . '/' . self::DETECTOR;
        self::assertFileExists($detector);
        self::assertTrue(is_executable($detector), self::DETECTOR . ' must be executable.');

        // The harness must delegate rather than keep a second, drifting copy.
        $harness = (string) file_get_contents($this->repoRoot . '/tests/PackagedForm/check-verified-config-import');
        self::assertStringContainsString('check-consumer-key-hygiene', $harness);
        self::assertStringNotContainsString(
            '-path "$consumer/vendor" -prune',
            $harness,
            'vendor must not be pruned from filename-based key detection.',
        );
    }

    /**
     * The four cases the review measured. Before the fix, the first row passed
     * detection — a raw private key under `vendor/` was invisible to both rules.
     *
     * @return iterable<string, array{0: string, 1: 'raw'|'base64', 2: bool}>
     */
    public static function custodyCases(): iterable
    {
        yield 'raw key file under vendor' => ['vendor/evil/manifest-signing.key', 'raw', true];
        yield 'encoded key bytes under vendor' => ['vendor/evil/notes.txt', 'base64', true];
        yield 'raw key file in the application' => ['manifest-signing.key', 'raw', true];
        yield 'encoded key bytes in the application' => ['app/notes.txt', 'base64', true];
        yield 'raw key bytes under an innocuous vendor name' => ['vendor/evil/notes.txt', 'raw', false];
    }

    #[Test]
    #[DataProvider('custodyCases')]
    public function custody_in_a_consumer_tree_is_refused(string $relative, string $encoding, bool $refused): void
    {
        $tree = $this->tree();
        $this->place($tree, $relative, $encoding);

        $result = $this->detect($tree);

        if ($refused) {
            self::assertSame(1, $result['code'], "Expected refusal for {$relative} ({$encoding}). {$result['stderr']}");
            self::assertNotSame('', trim($result['stderr']), 'A refusal must say what it found.');

            return;
        }
        // Documented residual: raw bytes under an innocuous name are outside
        // every rule. Rules 2 and 3 are filename rules and rule 1 is base64.
        // Recorded so the boundary is explicit rather than assumed.
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    #[Test]
    public function the_legitimate_defuse_distribution_artifacts_are_allowed(): void
    {
        $tree = $this->tree();
        foreach (['signingkey.asc', 'signingkey-new.asc', 'signingkey-new.asc.sig'] as $artifact) {
            $this->write($tree . '/' . self::DEFUSE_DIST . '/' . $artifact, "-----BEGIN PGP PUBLIC KEY BLOCK-----\n");
        }

        $result = $this->detect($tree);

        self::assertSame(0, $result['code'], 'Public defuse artifacts are not authoring custody. ' . $result['stderr']);
    }

    #[Test]
    public function the_defuse_exemption_is_narrow(): void
    {
        // Same basenames, different directory: still refused.
        $elsewhere = $this->tree();
        $this->write($elsewhere . '/vendor/other/dist/signingkey.asc', 'x');
        self::assertSame(1, $this->detect($elsewhere)['code'], 'Only the exact defuse dist directory is exempt.');

        // Right directory, but a *.key file: rule 2 judges it independently and
        // the exemption cannot reach it.
        $keyInDist = $this->tree();
        copy($this->privateKeyFile, $this->write($keyInDist . '/' . self::DEFUSE_DIST . '/signingkey.key', ''));
        self::assertSame(1, $this->detect($keyInDist)['code'], 'A *.key file is never exempt.');

        // Right directory, unrelated signing-named file: refused.
        $strayInDist = $this->tree();
        $this->write($strayInDist . '/' . self::DEFUSE_DIST . '/manifest-signing.txt', 'x');
        self::assertSame(1, $this->detect($strayInDist)['code'], 'Only signingkey*.asc[.sig] is exempt.');

        // Nested below the exempt directory: refused, so the allowlist cannot
        // be widened by burying a path inside it.
        $nested = $this->tree();
        $this->write($nested . '/' . self::DEFUSE_DIST . '/nested/signingkey.asc', 'x');
        self::assertSame(1, $this->detect($nested)['code'], 'The exemption does not descend.');
    }

    #[Test]
    public function a_clean_consumer_tree_passes(): void
    {
        $tree = $this->tree();
        $this->write($tree . '/config/waaseyaa.php', "<?php return [];\n");
        $this->write($tree . '/vendor/defuse/php-encryption/dist/signingkey.asc', 'public');

        self::assertSame(0, $this->detect($tree)['code']);
    }

    /** @return array{code:int, stderr:string} */
    private function detect(string $tree): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$this->repoRoot . '/' . self::DETECTOR, $tree, $this->privateKeyFile],
            $descriptors,
            $pipes,
        );
        self::assertIsResource($process);
        $stderr = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return ['code' => proc_close($process), 'stderr' => $stderr];
    }

    private function tree(): string
    {
        $tree = $this->work . '/tree_' . bin2hex(random_bytes(6));
        mkdir($tree . '/vendor', 0o700, true);

        return $tree;
    }

    private function place(string $tree, string $relative, string $encoding): void
    {
        $raw = (string) file_get_contents($this->privateKeyFile);
        $this->write($tree . '/' . $relative, $encoding === 'raw' ? $raw : base64_encode($raw));
    }

    private function write(string $path, string $contents): string
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
