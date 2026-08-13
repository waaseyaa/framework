<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

/**
 * @api
 */
interface MailerInterface
{
    public function send(Envelope $envelope): void;
}
