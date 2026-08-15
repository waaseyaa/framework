<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/** @internal */
final readonly class AdminBuildProcessResult
{
    public function __construct(
        public int $exitCode,
        public ?string $npmErrorCode = null,
    ) {}
}
