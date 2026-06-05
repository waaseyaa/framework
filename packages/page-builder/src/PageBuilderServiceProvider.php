<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Binds the page-builder decode registry for the container.
 *
 * Discovered via `extra.waaseyaa.providers`. Binds a {@see PageBuilderRegistry}
 * pre-loaded with the framework's shipped decoders (Elementor, Gutenberg, plain
 * HTML). Consumers resolve `PageBuilderRegistry::class` and call
 * {@see PageBuilderRegistry::decode()}.
 *
 * Apps shipping a decoder for a bespoke builder implement
 * {@see Discovery\HasPageBuilderDecodersInterface} and either add it to a
 * resolved registry via {@see PageBuilderRegistry::addDecoder()} or construct
 * their own registry; the contract is the stable surface, this binding is a
 * convenience default.
 *
 * @api
 */
final class PageBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(PageBuilderRegistry::class, static fn(): PageBuilderRegistry
            => PageBuilderRegistry::withDefaults());
    }
}
