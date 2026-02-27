<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Backend;

use Aurora\Cache\Backend\NullBackend;
use Aurora\Cache\CacheBackendInterface;
use PHPUnit\Framework\TestCase;

final class NullBackendTest extends TestCase
{
    private NullBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new NullBackend();
    }

    public function testGetAlwaysReturnsFalse(): void
    {
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testSetDoesNothing(): void
    {
        $this->backend->set('key', 'value');

        $this->assertFalse($this->backend->get('key'));
    }

    public function testGetMultipleReturnsEmptyArray(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $cids = ['a', 'b'];
        $result = $this->backend->getMultiple($cids);

        $this->assertSame([], $result);
    }

    public function testDeleteDoesNotError(): void
    {
        $this->backend->delete('anything');
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testDeleteMultipleDoesNotError(): void
    {
        $this->backend->deleteMultiple(['a', 'b']);
        $this->assertFalse($this->backend->get('a'));
    }

    public function testDeleteAllDoesNotError(): void
    {
        $this->backend->deleteAll();
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testInvalidateDoesNotError(): void
    {
        $this->backend->invalidate('anything');
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testInvalidateMultipleDoesNotError(): void
    {
        $this->backend->invalidateMultiple(['a', 'b']);
        $this->assertFalse($this->backend->get('a'));
    }

    public function testInvalidateAllDoesNotError(): void
    {
        $this->backend->invalidateAll();
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testRemoveBinDoesNotError(): void
    {
        $this->backend->removeBin();
        $this->assertFalse($this->backend->get('anything'));
    }

    public function testImplementsCacheBackendInterface(): void
    {
        $this->assertInstanceOf(CacheBackendInterface::class, $this->backend);
    }
}
