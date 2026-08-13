<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft\Exception;

/** @api */
final class StaleEntityRevisionException extends \RuntimeException
{
    public function __construct(
        public readonly int $expectedRevisionId,
        public readonly ?int $currentRevisionId,
    ) {
        parent::__construct(sprintf(
            'Entity revision is stale: expected %d, current %s',
            $expectedRevisionId,
            $currentRevisionId === null ? 'none' : (string) $currentRevisionId,
        ));
    }
}
