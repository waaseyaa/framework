<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Waaseyaa\SSR\Flash\FlashMessageService;

final class FlashTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly FlashMessageService $flashService,
    ) {}

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('flash_messages', $this->flashMessages(...)),
        ];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function flashMessages(): array
    {
        return $this->flashService->consumeAll();
    }
}
