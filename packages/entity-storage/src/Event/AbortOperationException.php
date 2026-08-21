<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

/**
 * @api
 *
 * Thrown by Before* subscribers to halt the operation.
 *
 * The exception propagates through
 * {@see \Waaseyaa\EntityStorage\EntityStorageCoordinator::write()} /
 * {@see \Waaseyaa\EntityStorage\EntityStorageCoordinator::delete()} to the caller.
 * No After* event fires. No backend writes occur after this is thrown.
 *
 * This class is final so existing `catch (AbortOperationException)` blocks
 * keep their original semantics. Save-advisory acknowledgement uses the
 * sibling {@see \Waaseyaa\EntityStorage\Exception\SaveAdvisoryAcknowledgementRequiredException}.
 */
final class AbortOperationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $subscriberFqcn = null,
    ) {
        parent::__construct($reason);
    }
}
