<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailMessage;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\Entity\FieldableInterface;

#[CoversClass(AuthMailer::class)]
final class AuthMailerTest extends TestCase
{
    /** @var list<MailMessage> */
    public array $sentMessages = [];
    private MailDriverInterface $driver;
    private Environment $twig;
    private AuthMailer $mailer;

    protected function setUp(): void
    {
        $this->sentMessages = [];

        $this->driver = new class($this) implements MailDriverInterface {
            public function __construct(private readonly AuthMailerTest $test) {}
            public function send(MailMessage $message): int
            {
                $this->test->sentMessages[] = $message;
                return 202;
            }
            public function isConfigured(): bool { return true; }
        };

        $this->twig = new Environment(new ArrayLoader([
            'email/password-reset.html.twig' => '<p>Reset: {{ reset_url }}</p>',
            'email/password-reset.txt.twig' => 'Reset: {{ reset_url }}',
            'email/email-verification.html.twig' => '<p>Verify: {{ verify_url }}</p>',
            'email/email-verification.txt.twig' => 'Verify: {{ verify_url }}',
            'email/welcome.html.twig' => '<p>Welcome {{ user_name }}</p>',
            'email/welcome.txt.twig' => 'Welcome {{ user_name }}',
        ]));

        $this->mailer = new AuthMailer(
            driver: $this->driver,
            twig: $this->twig,
            baseUrl: 'https://example.com',
            appName: 'TestApp',
        );
    }

    #[Test]
    public function sends_password_reset_email(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Alice'],
            ['mail', 'alice@example.com'],
        ]);

        $this->mailer->sendPasswordReset($user, 'abc123');

        $this->assertCount(1, $this->sentMessages);
        $msg = $this->sentMessages[0];
        $this->assertSame('alice@example.com', $msg->to);
        $this->assertSame('Reset your TestApp password', $msg->subject);
        $this->assertStringContainsString('reset-password?token=abc123', $msg->htmlBody);
    }

    #[Test]
    public function sends_email_verification(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Bob'],
            ['mail', 'bob@example.com'],
        ]);

        $this->mailer->sendEmailVerification($user, 'xyz789');

        $this->assertCount(1, $this->sentMessages);
        $msg = $this->sentMessages[0];
        $this->assertSame('Verify your email for TestApp', $msg->subject);
        $this->assertStringContainsString('verify-email?token=xyz789', $msg->htmlBody);
    }

    #[Test]
    public function sends_welcome_email(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Carol'],
            ['mail', 'carol@example.com'],
        ]);

        $this->mailer->sendWelcome($user);

        $this->assertCount(1, $this->sentMessages);
        $msg = $this->sentMessages[0];
        $this->assertSame('Welcome to TestApp', $msg->subject);
        $this->assertStringContainsString('Welcome Carol', $msg->htmlBody);
    }

    #[Test]
    public function skips_sending_when_driver_not_configured(): void
    {
        $unconfigured = new class implements MailDriverInterface {
            public function send(MailMessage $message): int { return 202; }
            public function isConfigured(): bool { return false; }
        };

        $mailer = new AuthMailer($unconfigured, $this->twig, 'https://example.com', 'App');
        $user = $this->createMock(FieldableInterface::class);

        $mailer->sendPasswordReset($user, 'token');
        $this->assertCount(0, $this->sentMessages);
    }
}
