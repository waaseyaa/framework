<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Flash;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\Flash\Flash;
use Waaseyaa\SSR\Flash\FlashMessageService;

#[CoversClass(Flash::class)]
final class FlashTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        Flash::setService(new FlashMessageService());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Flash::resetService();
    }

    #[Test]
    public function success_delegates_to_service(): void
    {
        Flash::success('Saved');
        $messages = (new FlashMessageService())->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('success', $messages[0]['type']);
    }

    #[Test]
    public function error_delegates_to_service(): void
    {
        Flash::error('Failed');
        $messages = (new FlashMessageService())->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('error', $messages[0]['type']);
    }

    #[Test]
    public function info_delegates_to_service(): void
    {
        Flash::info('Note');
        $messages = (new FlashMessageService())->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('info', $messages[0]['type']);
    }
}
