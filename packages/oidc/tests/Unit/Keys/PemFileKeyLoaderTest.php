<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Keys;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\PemFileKeyLoader;
use Waaseyaa\Oidc\Keys\SigningKey;

#[CoversClass(PemFileKeyLoader::class)]
#[CoversClass(SigningKey::class)]
final class PemFileKeyLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_oidc_keyloader_' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }

    #[Test]
    public function loadSigningKeysReadsConfiguredPemFiles(): void
    {
        [$publicPath, $privatePath] = $this->writeRsaKeypair('key-a');

        $loader = PemFileKeyLoader::fromConfig([
            'key-a' => [
                'algorithm' => 'RS256',
                'public_key_path' => $publicPath,
                'private_key_path' => $privatePath,
            ],
        ]);

        $keys = $loader->loadSigningKeys();

        self::assertCount(1, $keys);
        self::assertInstanceOf(SigningKey::class, $keys[0]);
        self::assertSame('key-a', $keys[0]->kid);
        self::assertSame('RS256', $keys[0]->algorithm);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $keys[0]->publicKeyPem);
        self::assertNotNull($keys[0]->privateKeyPem);
        self::assertStringContainsString('PRIVATE KEY', $keys[0]->privateKeyPem);
    }

    #[Test]
    public function loadSigningKeysAcceptsPublicOnlyConfiguration(): void
    {
        [$publicPath] = $this->writeRsaKeypair('key-pub-only');

        $loader = PemFileKeyLoader::fromConfig([
            'key-pub-only' => [
                'algorithm' => 'RS256',
                'public_key_path' => $publicPath,
            ],
        ]);

        $keys = $loader->loadSigningKeys();

        self::assertCount(1, $keys);
        self::assertSame('key-pub-only', $keys[0]->kid);
        self::assertNull($keys[0]->privateKeyPem);
    }

    #[Test]
    public function loadSigningKeysSortsByKidForDeterministicOutput(): void
    {
        [$publicA] = $this->writeRsaKeypair('z-key');
        [$publicB] = $this->writeRsaKeypair('a-key');

        $loader = PemFileKeyLoader::fromConfig([
            'z-key' => ['algorithm' => 'RS256', 'public_key_path' => $publicA],
            'a-key' => ['algorithm' => 'RS256', 'public_key_path' => $publicB],
        ]);

        $keys = $loader->loadSigningKeys();

        self::assertSame(['a-key', 'z-key'], array_map(static fn(SigningKey $k): string => $k->kid, $keys));
    }

    #[Test]
    public function fromConfigThrowsWhenConfigIsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No OIDC signing keys configured');

        PemFileKeyLoader::fromConfig([]);
    }

    #[Test]
    public function loadSigningKeysThrowsWhenPublicKeyFileIsMissing(): void
    {
        $loader = PemFileKeyLoader::fromConfig([
            'missing' => [
                'algorithm' => 'RS256',
                'public_key_path' => $this->tmpDir . '/does-not-exist.pub.pem',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does-not-exist.pub.pem');

        $loader->loadSigningKeys();
    }

    #[Test]
    public function fromDirectoryScansPemPairsByFilename(): void
    {
        $this->writeRsaKeypair('scan-a');
        $this->writeRsaKeypair('scan-b');

        $loader = PemFileKeyLoader::fromDirectory($this->tmpDir);

        $keys = $loader->loadSigningKeys();

        self::assertSame(['scan-a', 'scan-b'], array_map(static fn(SigningKey $k): string => $k->kid, $keys));
        foreach ($keys as $key) {
            self::assertSame('RS256', $key->algorithm);
            self::assertNotNull($key->privateKeyPem);
        }
    }

    #[Test]
    public function fromDirectoryThrowsWhenDirectoryContainsNoPublicKeys(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No OIDC signing keys found');

        PemFileKeyLoader::fromDirectory($this->tmpDir);
    }

    #[Test]
    public function fromDirectoryThrowsWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        PemFileKeyLoader::fromDirectory($this->tmpDir . '/nope');
    }

    /**
     * @return array{0: string, 1: string} [public_path, private_path]
     */
    private function writeRsaKeypair(string $kid): array
    {
        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();

        $publicPath = $this->tmpDir . '/' . $kid . '.pub.pem';
        $privatePath = $this->tmpDir . '/' . $kid . '.key.pem';

        file_put_contents($publicPath, $keyPair['public']);
        file_put_contents($privatePath, $keyPair['private']);

        return [$publicPath, $privatePath];
    }
}
