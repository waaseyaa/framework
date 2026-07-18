<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\FieldReadLevel;

/** Boot-compiled immutable accessor rule; resolving metadata is never a hot-read operation. @internal */
final readonly class CompiledFieldReadRule
{
    public function __construct(public string $field, public FieldReadLevel $level) {}
}
