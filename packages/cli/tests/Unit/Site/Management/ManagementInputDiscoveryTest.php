<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Management;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Management\ManagementInputDiscovery;

final class ManagementInputDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/management_inputs_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testOrderIsStableAndOnlyGitMetadataIsExcluded(): void
    {
        file_put_contents($this->root . '/z.go', 'z');
        file_put_contents($this->root . '/a.sql', 'a');
        $discovery = new ManagementInputDiscovery();
        $before = $discovery->digest($this->root);
        unlink($this->root . '/z.go');
        file_put_contents($this->root . '/z.go', 'z');
        self::assertSame($before, $discovery->digest($this->root));
        mkdir($this->root . '/.git');
        file_put_contents($this->root . '/.git/HEAD', 'private metadata');
        self::assertSame($before, $discovery->digest($this->root));
        mkdir($this->root . '/empty');
        self::assertNotSame($before, $discovery->digest($this->root));
    }

    public function testRenameDeletionAndExecutableModeInvalidateIdentity(): void
    {
        $discovery = new ManagementInputDiscovery();
        $empty = $discovery->digest($this->root);
        file_put_contents($this->root . '/verify', 'same');
        chmod($this->root . '/verify', 0600);
        $before = $discovery->digest($this->root);
        chmod($this->root . '/verify', 0700);
        self::assertNotSame($before, $discovery->digest($this->root));
        $before = $discovery->digest($this->root);
        rename($this->root . '/verify', $this->root . '/check');
        self::assertNotSame($before, $discovery->digest($this->root));
        unlink($this->root . '/check');
        self::assertSame($empty, $discovery->digest($this->root));
    }

    public function testSymlinkInputsAreRefusedInsteadOfOmitted(): void
    {
        file_put_contents($this->root . '/regular', 'bytes');
        self::assertTrue(symlink($this->root . '/regular', $this->root . '/link'));
        $this->expectException(\RuntimeException::class);
        new ManagementInputDiscovery()->digest($this->root);
    }
}
