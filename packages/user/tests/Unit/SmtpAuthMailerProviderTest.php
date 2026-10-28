<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;
use Waaseyaa\User\UserServiceProvider;

final class SmtpAuthMailerProviderTest extends TestCase
{
    public function test_smtp_enables_reset_verification_and_welcome_through_the_real_provider_binding(): void
    {
        $sink = new class implements MailerInterface {
            public array $messages = [];
            public function send(Envelope $envelope): void
            {
                $this->messages[] = $envelope;
            }
        };
        $templates = [];
        foreach (['password-reset', 'email-verification', 'welcome'] as $template) {
            $templates['email/' . $template . '.html.twig'] = '<p>{{ user_name }} {{ reset_url|default(verify_url|default(home_url|default(""))) }}</p>';
            $templates['email/' . $template . '.txt.twig'] = '{{ user_name }} {{ reset_url|default(verify_url|default(home_url|default(""))) }}';
        }
        $services = $this->createStub(KernelServicesInterface::class);
        $twig = new Environment(new ArrayLoader($templates));
        $fields = new UserInternalFieldReaderFixture();
        $services->method('get')->willReturnCallback(static fn(string $id): ?object => match ($id) {
            MailerInterface::class => $sink, Environment::class => $twig, UserInternalFieldReaderInterface::class => $fields, default => null,
        });
        $provider = new UserServiceProvider();
        $provider->setKernelContext('/tmp/smtp-test', ['app' => ['url' => 'https://nation.example.test'], 'mail' => [
            'transport' => 'smtp', 'from_address' => 'webforms@example.test', 'smtp' => [
                'host' => 'smtp.office365.com', 'port' => 587, 'username' => 'webforms@example.test', 'password' => 'dummy-password', 'auth' => 'login',
            ],
        ]], []);
        $provider->setKernelServices($services);
        $provider->register();
        $mailer = $provider->resolve(AuthMailer::class);
        self::assertTrue($mailer->isConfigured());
        $user = new User(['uid' => 123, 'name' => 'Test Member', 'mail' => 'member@example.test']);
        $mailer->sendPasswordReset($user, 'reset-token');
        $mailer->sendEmailVerification($user, 'verify-token');
        $mailer->sendWelcome($user);
        self::assertCount(3, $sink->messages);
        self::assertStringContainsString('https://nation.example.test/reset-password?token=reset-token', $sink->messages[0]->textBody);
        self::assertSame(['member@example.test'], $sink->messages[2]->to);
    }
}
