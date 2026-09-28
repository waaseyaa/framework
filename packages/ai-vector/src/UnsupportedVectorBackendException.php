<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

final class UnsupportedVectorBackendException extends \RuntimeException
{
    public function __construct(mixed $backend)
    {
        $display = is_string($backend) ? $backend : get_debug_type($backend);

        parent::__construct(sprintf(
            '[AIV-BACKEND-001] Vector backend "%s" is not supported. Use "database" or disable ai.vector_enabled.',
            $display,
        ));
    }
}
