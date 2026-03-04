<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

final readonly class Theme implements ThemeInterface
{
    /**
     * @param list<string> $templateDirectories
     */
    public function __construct(
        private string $id,
        private array $templateDirectories,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function templateDirectories(): array
    {
        return $this->templateDirectories;
    }
}
