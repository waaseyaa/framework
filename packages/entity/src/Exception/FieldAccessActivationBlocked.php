<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Exception;

/** Raised when a checksum-bound preflight still contains activation blockers. @api */
final class FieldAccessActivationBlocked extends \LogicException {}
