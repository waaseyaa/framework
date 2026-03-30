<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Config;

enum MailMissingPolicy: string
{
    case DevLog = 'dev-log';
    case Fail = 'fail';
    case Silent = 'silent';
}
