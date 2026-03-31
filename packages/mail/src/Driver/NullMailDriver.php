<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Driver;

use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailMessage;

final class NullMailDriver implements MailDriverInterface
{
    public function send(MailMessage $message): int
    {
        return 200;
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
