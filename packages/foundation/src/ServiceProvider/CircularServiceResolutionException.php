<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

/**
 * Raised when {@see ServiceProvider::resolve()} or
 * {@see \Waaseyaa\Foundation\Kernel\KernelHandlerContainer::get()} detects
 * that an abstract identifier is already in-flight further up the same
 * resolution call stack — a circular dependency between binding factories
 * (or auto-wired constructors) that would otherwise re-enter forever, to
 * stack/memory exhaustion.
 *
 * The diagnostic carries ONLY abstract identifiers (binding keys /
 * class-strings) — never factory arguments, constructor arguments, resolved
 * service values, config values, or secrets. It is safe to log verbatim.
 *
 * Extends {@see \RuntimeException} so every existing `catch (\RuntimeException)`
 * site (e.g. {@see ServiceProvider::resolveOptional()}) keeps behaving
 * exactly as it does for any other resolution failure. The rendered message
 * deliberately does not start with the "No binding registered for " prefix
 * {@see ServiceProvider::resolve()} uses for a genuinely unbound abstract, so
 * {@see \Waaseyaa\Foundation\Kernel\KernelHandlerContainer::get()} re-throws
 * a cycle instead of misreporting it as an unbound id.
 *
 * @api
 */
final class CircularServiceResolutionException extends \RuntimeException
{
    /**
     * @param list<string> $cycle ordered abstract identifiers from the first
     *                            re-entered abstract to the repeat — the
     *                            last element equals the first, e.g.
     *                            `['A', 'B', 'A']` for `A -> B -> A`.
     */
    public function __construct(public readonly array $cycle)
    {
        parent::__construct(sprintf(
            'Circular service resolution detected: %s.',
            implode(' -> ', $cycle),
        ));
    }
}
