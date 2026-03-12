<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

interface MailerInterface
{
    public function send(Envelope $envelope): void;
}
