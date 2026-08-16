<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Lease;

/** The authority could not prove whether a lease mutation committed. @api */
final class LeaseAmbiguousException extends \RuntimeException {}
