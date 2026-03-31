<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Twig\Environment;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailMessage;

class AuthMailer
{
    private ?MailDriverInterface $resolvedDriver = null;

    public function __construct(
        private readonly MailDriverInterface|\Closure $driver,
        private readonly Environment $twig,
        private readonly string $baseUrl,
        private readonly string $appName,
    ) {}

    private function driver(): MailDriverInterface
    {
        if ($this->resolvedDriver === null) {
            $this->resolvedDriver = $this->driver instanceof \Closure
                ? ($this->driver)()
                : $this->driver;
        }

        return $this->resolvedDriver;
    }

    public function isConfigured(): bool
    {
        return $this->driver()->isConfigured();
    }

    public function sendPasswordReset(FieldableInterface $user, string $token): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'reset_url' => $this->baseUrl . '/reset-password?token=' . $token,
        ];

        $html = $this->twig->render('email/password-reset.html.twig', $vars);
        $text = $this->twig->render('email/password-reset.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Reset your {$this->appName} password",
            body: $text,
            htmlBody: $html,
        ));
    }

    public function sendEmailVerification(FieldableInterface $user, string $token): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'verify_url' => $this->baseUrl . '/verify-email?token=' . $token,
        ];

        $html = $this->twig->render('email/email-verification.html.twig', $vars);
        $text = $this->twig->render('email/email-verification.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Verify your email for {$this->appName}",
            body: $text,
            htmlBody: $html,
        ));
    }

    public function sendWelcome(FieldableInterface $user): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'home_url' => $this->baseUrl,
        ];

        $html = $this->twig->render('email/welcome.html.twig', $vars);
        $text = $this->twig->render('email/welcome.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Welcome to {$this->appName}",
            body: $text,
            htmlBody: $html,
        ));
    }
}
