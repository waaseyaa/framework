<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation;

use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Community\CommunityContextInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfig;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;

final class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(SovereigntyConfigInterface::class, fn() => SovereigntyConfig::fromArray($this->config));
        $this->singleton(CommunityContextInterface::class, CommunityContext::class);
    }
}
