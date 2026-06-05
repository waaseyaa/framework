<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Discovery;

use Waaseyaa\PageBuilder\Contract\PageBuilderDecoderInterface;

/**
 * Provider capability for contributing page-builder decoders.
 *
 * An application or extension package that ships a decoder for a builder the
 * framework does not cover (a bespoke or proprietary builder) implements this
 * on its `ServiceProvider`. Mirrors the migration package's
 * `HasMigrationsInterface`: a provider advertises capability objects the
 * framework collects.
 *
 * The framework's {@see \Waaseyaa\PageBuilder\PageBuilderServiceProvider} binds
 * a default registry; consumers that want app decoders included can read these
 * providers and {@see \Waaseyaa\PageBuilder\PageBuilderRegistry::addDecoder()}
 * them.
 *
 * @api
 */
interface HasPageBuilderDecodersInterface
{
    /**
     * @return iterable<PageBuilderDecoderInterface>
     */
    public function pageBuilderDecoders(): iterable;
}
