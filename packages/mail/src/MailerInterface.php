<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

/**
 * @internal
 */
interface MailerInterface
{
    public function send(Envelope $envelope): void;
}
