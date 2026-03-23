<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(ErrorLogHandler::class)]
final class ErrorLogHandlerTest extends TestCase
{
    #[Test]
    public function formats_message_with_level(): void
    {
        $lines = [];
        $logger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->error('Something broke');

        $this->assertCount(1, $lines);
        $this->assertSame('[error] Something broke', $lines[0]);
    }

    #[Test]
    public function includes_context_as_json(): void
    {
        $lines = [];
        $logger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->warning('Disk full', ['disk' => '/dev/sda1', 'usage' => 99]);

        $this->assertCount(1, $lines);
        $this->assertSame('[warning] Disk full {"disk":"/dev/sda1","usage":99}', $lines[0]);
    }

    #[Test]
    public function omits_context_when_empty(): void
    {
        $lines = [];
        $logger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->info('All good');

        $this->assertSame('[info] All good', $lines[0]);
    }

    #[Test]
    public function delegates_convenience_methods_to_log(): void
    {
        $lines = [];
        $logger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->emergency('e');
        $logger->alert('a');
        $logger->critical('c');
        $logger->error('err');
        $logger->warning('w');
        $logger->notice('n');
        $logger->info('i');
        $logger->debug('d');

        $this->assertCount(8, $lines);
        $this->assertStringStartsWith('[emergency]', $lines[0]);
        $this->assertStringStartsWith('[alert]', $lines[1]);
        $this->assertStringStartsWith('[critical]', $lines[2]);
        $this->assertStringStartsWith('[error]', $lines[3]);
        $this->assertStringStartsWith('[warning]', $lines[4]);
        $this->assertStringStartsWith('[notice]', $lines[5]);
        $this->assertStringStartsWith('[info]', $lines[6]);
        $this->assertStringStartsWith('[debug]', $lines[7]);
    }

    #[Test]
    public function accepts_stringable_message(): void
    {
        $lines = [];
        $logger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };

        $logger->log(LogLevel::INFO, $stringable);

        $this->assertSame('[info] stringable message', $lines[0]);
    }
}
