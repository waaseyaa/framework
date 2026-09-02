<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Provider capability: the provider's contributions depend on optional packages.
 *
 * A provider that imports types from a package its own manifest lists only
 * under `suggest` or `require-dev` declares that dependency here. When any
 * declared requirement is unsatisfied, the kernel treats the provider as
 * contributing nothing: package discovery omits it from the console-command
 * provider roster, and the console runtime registers none of its commands.
 * The provider's own `register()` and `consoleCommands()` must honour the
 * same verdict through {@see OptionalPackageGate}, so metadata, discovery,
 * `list`/`help` output, and invocation never disagree.
 *
 * This is the fail-closed alternative to registering a command whose handler
 * cannot resolve. It is declared statically so that discovery, which sees only
 * class names, and the runtime, which holds instances, evaluate one source.
 * Consumers never filter commands or bind stubs; absence is decided here.
 *
 * @api
 */
interface RequiresOptionalPackagesInterface
{
    /** @return iterable<OptionalPackageRequirement> */
    public static function optionalPackageRequirements(): iterable;
}
