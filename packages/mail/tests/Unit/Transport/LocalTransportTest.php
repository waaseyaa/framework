<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\LocalTransport;

#[CoversClass(LocalTransport::class)]
final class LocalTransportTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/waaseyaa_mail_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    #[Test]
    public function send_writes_to_log_file(): void
    {
        $transport = new LocalTransport($this->logPath);
        $envelope = new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test Subject',
        );

        $transport->send($envelope);

        $this->assertFileExists($this->logPath);
        $contents = file_get_contents($this->logPath);
        $this->assertStringContainsString('user@example.com', $contents);
        $this->assertStringContainsString('noreply@example.com', $contents);
        $this->assertStringContainsString('Test Subject', $contents);
    }

    #[Test]
    public function send_appends_to_existing_log(): void
    {
        $transport = new LocalTransport($this->logPath);
        $transport->send(new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'First'));
        $transport->send(new Envelope(to: ['c@test.com'], from: 'd@test.com', subject: 'Second'));

        $contents = file_get_contents($this->logPath);
        $this->assertStringContainsString('First', $contents);
        $this->assertStringContainsString('Second', $contents);
    }
}
