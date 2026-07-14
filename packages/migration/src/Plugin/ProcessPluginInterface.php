<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin;

/**
 * Contract every process plugin implements.
 *
 * Process plugins are the middle stage of the pipeline. Each call transforms
 * one value at a time for one destination field; the migration runner composes
 * multiple process plugins into a chain per destination field, threading the
 * output of step N into the input of step N+1 via the `$value` argument and
 * keeping the surrounding {@see ProcessContext} otherwise unchanged.
 *
 * Implementations SHOULD be pure functions of `($value, $context)`. Side
 * effects (logging, metrics) are tolerated but ordering must not depend on
 * them.
 *
 * @api
 */
interface ProcessPluginInterface
{
    /**
     * Globally unique plugin identifier (snake_case, e.g. `html_sanitize`).
     *
     * Built-in plugins use ids from {@see ReservedPluginIds}.
     */
    public function id(): string;

    /**
     * Plugin stability marker — either `'stable'` or `'experimental'`.
     *
     * Experimental plugins emit a deprecation notice on first use per process,
     * via the {@see \Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION}
     * channel.
     */
    public function stability(): string;

    /**
     * Transform one value for one destination field.
     *
     * In a multi-plugin chain, the framework hands plugin N+1 the return value
     * of plugin N as `$value`. The framework does not enforce per-step type
     * signatures — chain authors are responsible for type compatibility
     * between adjacent steps.
     */
    public function transform(mixed $value, ProcessContext $context): mixed;
}
