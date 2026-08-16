<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Authority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\ConfigurationAuthorityResolver;
use Waaseyaa\Config\Authority\SyncArtifactStorageAdapter;

final class SyncArtifactStorageAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_cfg_sync_adapter_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    #[Test]
    public function finalDirectoryCreatedOnWriteIsPinnedAgainstReplacement(): void
    {
        $context = (new ConfigurationAuthorityResolver())->resolve($this->root, 'sqlite-primary', [], []);
        $adapter = new SyncArtifactStorageAdapter($context);
        self::assertTrue($adapter->write('system.site', ['name' => 'Waaseyaa']));
        self::assertTrue(rename($context->syncPath, $this->root . '/original-sync'));
        self::assertTrue(mkdir($context->syncPath, 0o700, true));

        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('identity changed after its first authorized use');
        $adapter->listAll();
    }

    #[Test]
    public function symbolicLinkMemberIsRejectedBeforeRead(): void
    {
        mkdir($this->root . '/storage/config-sync', 0o700, true);
        $outside = $this->root . '/outside.yml';
        file_put_contents($outside, "name: Outside\n");
        symlink($outside, $this->root . '/storage/config-sync/system.site.yml');
        $context = (new ConfigurationAuthorityResolver())->resolve($this->root, 'sqlite-primary', [], []);
        $adapter = new SyncArtifactStorageAdapter($context);

        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('regular non-link file');
        $adapter->read('system.site');
    }

    #[Test]
    public function symbolicLinkMemberIsRejectedDuringEnumeration(): void
    {
        mkdir($this->root . '/storage/config-sync', 0o700, true);
        $outside = $this->root . '/outside.yml';
        file_put_contents($outside, "name: Outside\n");
        symlink($outside, $this->root . '/storage/config-sync/system.site.yml');
        $context = (new ConfigurationAuthorityResolver())->resolve($this->root, 'sqlite-primary', [], []);
        $adapter = new SyncArtifactStorageAdapter($context);

        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('regular non-link file');
        $adapter->listAll();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
