<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

interface ThemeInterface
{
    public function id(): string;

    /**
     * @return list<string>
     */
    public function templateDirectories(): array;
}
