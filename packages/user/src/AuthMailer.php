<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Twig\Environment;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailerInterface;

class AuthMailer
{
    /**
     * @param ?Environment $twig Template engine for rendering the mail bodies.
     *        Nullable so the Layer-1 user package does not have to reach up to
     *        the Layer-6 SSR package to obtain one: the service provider passes
     *        whatever `Twig\Environment` is registered in the container (SSR
     *        registers it), or null when no SSR/Twig is installed. With no
     *        renderer, auth email is simply not configured — {@see isConfigured()}.
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly bool $authEmailConfigured,
        private readonly ?Environment $twig,
        private readonly string $baseUrl,
        private readonly string $appName,
        private readonly UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function isConfigured(): bool
    {
        return $this->authEmailConfigured && $this->twig !== null;
    }

    public function sendPasswordReset(EntityInterface $user, string $token, ?AuthMailPresentation $presentation = null): void
    {
        if (!$this->authEmailConfigured || $this->twig === null) {
            return;
        }

        $identity = $this->internalFields->mailDelivery($user);
        $vars = array_replace($presentation === null ? [] : $presentation->variables, [
            'user_name' => $identity->name,
            'reset_url' => $this->baseUrl . '/reset-password?token=' . rawurlencode($token),
        ]);

        $html = $this->twig->render($presentation === null ? 'email/password-reset.html.twig' : $presentation->htmlTemplate, $vars);
        $text = $this->twig->render($presentation === null ? 'email/password-reset.txt.twig' : $presentation->textTemplate, $vars);

        $this->mailer->send(new Envelope(
            to: $this->recipientList($identity->mail),
            from: '',
            subject: $presentation === null ? "Reset your {$this->appName} password" : $presentation->subject,
            textBody: $text,
            htmlBody: $html,
        ));
    }

    public function sendEmailVerification(EntityInterface $user, string $token, ?AuthMailPresentation $presentation = null): void
    {
        if (!$this->authEmailConfigured || $this->twig === null) {
            return;
        }

        $identity = $this->internalFields->mailDelivery($user);
        $vars = array_replace($presentation === null ? [] : $presentation->variables, [
            'user_name' => $identity->name,
            'verify_url' => $this->baseUrl . '/verify-email?token=' . rawurlencode($token),
        ]);

        $html = $this->twig->render($presentation === null ? 'email/email-verification.html.twig' : $presentation->htmlTemplate, $vars);
        $text = $this->twig->render($presentation === null ? 'email/email-verification.txt.twig' : $presentation->textTemplate, $vars);

        $this->mailer->send(new Envelope(
            to: $this->recipientList($identity->mail),
            from: '',
            subject: $presentation === null ? "Verify your email for {$this->appName}" : $presentation->subject,
            textBody: $text,
            htmlBody: $html,
        ));
    }

    public function sendWelcome(EntityInterface $user, ?AuthMailPresentation $presentation = null): void
    {
        if (!$this->authEmailConfigured || $this->twig === null) {
            return;
        }

        $identity = $this->internalFields->mailDelivery($user);
        $vars = array_replace($presentation === null ? [] : $presentation->variables, [
            'user_name' => $identity->name,
            'home_url' => $this->baseUrl,
        ]);

        $html = $this->twig->render($presentation === null ? 'email/welcome.html.twig' : $presentation->htmlTemplate, $vars);
        $text = $this->twig->render($presentation === null ? 'email/welcome.txt.twig' : $presentation->textTemplate, $vars);

        $this->mailer->send(new Envelope(
            to: $this->recipientList($identity->mail),
            from: '',
            subject: $presentation === null ? "Welcome to {$this->appName}" : $presentation->subject,
            textBody: $text,
            htmlBody: $html,
        ));
    }

    /**
     * @return list<string>
     */
    private function recipientList(mixed $mail): array
    {
        if (!is_string($mail) || $mail === '') {
            return [];
        }

        return [$mail];
    }
}
