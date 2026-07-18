<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Exception;

/** Signed failed-job payload could not be authenticated for exact replay. @api */
final class InvalidPersistentPayload extends \RuntimeException {}
