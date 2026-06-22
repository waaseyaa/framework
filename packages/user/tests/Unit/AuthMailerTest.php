<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\User\AuthMailer;

#[CoversClass(AuthMailer::class)]
final class AuthMailerTest extends TestCase
{
    /** @var list<Envelope> */
    public array $sentEnvelopes = [];

    private MailerInterface $mailer;

    private Environment $twig;

    private AuthMailer $authMailer;

    protected function setUp(): void
    {
        $this->sentEnvelopes = [];

        $this->mailer = new class($this) implements MailerInterface {
            public function __construct(private readonly AuthMailerTest $test) {}

            public function send(Envelope $envelope): void
            {
                $this->test->sentEnvelopes[] = $envelope;
            }
        };

        $this->twig = new Environment(new ArrayLoader([
            'email/password-reset.html.twig' => '<p>Reset: {{ reset_url }}</p>',
            'email/password-reset.txt.twig' => 'Reset: {{ reset_url }}',
            'email/email-verification.html.twig' => '<p>Verify: {{ verify_url }}</p>',
            'email/email-verification.txt.twig' => 'Verify: {{ verify_url }}',
            'email/welcome.html.twig' => '<p>Welcome {{ user_name }}</p>',
            'email/welcome.txt.twig' => 'Welcome {{ user_name }}',
        ]));

        $this->authMailer = new AuthMailer(
            mailer: $this->mailer,
            authEmailConfigured: true,
            twig: $this->twig,
            baseUrl: 'https://example.com',
            appName: 'TestApp',
        );
    }

    #[Test]
    public function is_unconfigured_and_sends_nothing_without_a_twig_renderer(): void
    {
        // When no Twig renderer is available (e.g. SSR is not installed, so the
        // user package cannot — and must not — reach up to it for one), the
        // mailer is simply unconfigured and silently sends nothing rather than
        // fataling on a missing renderer.
        $mailer = new AuthMailer(
            mailer: $this->mailer,
            authEmailConfigured: true,
            twig: null,
            baseUrl: 'https://example.com',
            appName: 'TestApp',
        );

        self::assertFalse($mailer->isConfigured());

        $user = $this->createMock(FieldableInterface::class);
        $mailer->sendPasswordReset($user, 'abc123');
        $mailer->sendEmailVerification($user, 'def456');
        $mailer->sendWelcome($user);

        self::assertSame([], $this->sentEnvelopes);
    }

    #[Test]
    public function sends_password_reset_email(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Alice'],
            ['mail', 'alice@example.com'],
        ]);

        $this->authMailer->sendPasswordReset($user, 'abc123');

        $this->assertCount(1, $this->sentEnvelopes);
        $env = $this->sentEnvelopes[0];
        $this->assertSame(['alice@example.com'], $env->to);
        $this->assertSame('Reset your TestApp password', $env->subject);
        $this->assertStringContainsString('reset-password?token=abc123', $env->htmlBody);
    }

    #[Test]
    public function sends_email_verification(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Bob'],
            ['mail', 'bob@example.com'],
        ]);

        $this->authMailer->sendEmailVerification($user, 'xyz789');

        $this->assertCount(1, $this->sentEnvelopes);
        $env = $this->sentEnvelopes[0];
        $this->assertSame('Verify your email for TestApp', $env->subject);
        $this->assertStringContainsString('verify-email?token=xyz789', $env->htmlBody);
    }

    #[Test]
    public function sends_welcome_email(): void
    {
        $user = $this->createMock(FieldableInterface::class);
        $user->method('get')->willReturnMap([
            ['name', 'Carol'],
            ['mail', 'carol@example.com'],
        ]);

        $this->authMailer->sendWelcome($user);

        $this->assertCount(1, $this->sentEnvelopes);
        $env = $this->sentEnvelopes[0];
        $this->assertSame('Welcome to TestApp', $env->subject);
        $this->assertStringContainsString('Welcome Carol', $env->htmlBody);
    }

    #[Test]
    public function skips_sending_when_not_configured(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $authMailer = new AuthMailer(
            mailer: $mailer,
            authEmailConfigured: false,
            twig: $this->twig,
            baseUrl: 'https://example.com',
            appName: 'App',
        );
        $user = $this->createMock(FieldableInterface::class);

        $authMailer->sendPasswordReset($user, 'token');
    }

    #[Test]
    public function is_configured_reflects_constructor_flag(): void
    {
        $configured = new AuthMailer(
            mailer: $this->mailer,
            authEmailConfigured: true,
            twig: $this->twig,
            baseUrl: 'https://example.com',
            appName: 'App',
        );
        $this->assertTrue($configured->isConfigured());

        $notConfigured = new AuthMailer(
            mailer: $this->mailer,
            authEmailConfigured: false,
            twig: $this->twig,
            baseUrl: 'https://example.com',
            appName: 'App',
        );
        $this->assertFalse($notConfigured->isConfigured());
    }
}
