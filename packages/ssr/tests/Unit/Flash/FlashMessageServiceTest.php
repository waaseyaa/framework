<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Flash;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\Flash\FlashMessageService;

#[CoversClass(FlashMessageService::class)]
final class FlashMessageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function adds_and_consumes_success_message(): void
    {
        $service = new FlashMessageService();
        $service->addSuccess('It worked');

        $messages = $service->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('success', $messages[0]['type']);
        $this->assertSame('It worked', $messages[0]['message']);
    }

    #[Test]
    public function adds_and_consumes_error_message(): void
    {
        $service = new FlashMessageService();
        $service->addError('Something broke');

        $messages = $service->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('error', $messages[0]['type']);
    }

    #[Test]
    public function adds_and_consumes_info_message(): void
    {
        $service = new FlashMessageService();
        $service->addInfo('FYI');

        $messages = $service->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('info', $messages[0]['type']);
    }

    #[Test]
    public function consume_clears_messages(): void
    {
        $service = new FlashMessageService();
        $service->addSuccess('First');
        $service->consumeAll();

        $this->assertSame([], $service->consumeAll());
    }

    #[Test]
    public function returns_empty_array_when_no_messages(): void
    {
        $service = new FlashMessageService();
        $this->assertSame([], $service->consumeAll());
    }

    #[Test]
    public function filters_invalid_session_data(): void
    {
        $_SESSION['flash_messages'] = [
            ['type' => 'success', 'message' => 'valid'],
            'not an array',
            ['type' => 'invalid_type', 'message' => 'bad type'],
            ['type' => 'success', 'message' => ''],
        ];

        $service = new FlashMessageService();
        $messages = $service->consumeAll();
        $this->assertCount(1, $messages);
        $this->assertSame('valid', $messages[0]['message']);
    }
}
