<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/** @internal */
enum AdminBuildPlatform
{
    case Linux;
    case Windows;

    public static function host(): self
    {
        return PHP_OS_FAMILY === 'Windows' ? self::Windows : self::Linux;
    }
}
