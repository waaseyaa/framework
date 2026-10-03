<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Bootstrap admission token. Inspection cannot refresh it. @api */
final readonly class ValidatedRouteParticipation
{
    private function __construct(public array $records, public string $compilerIdentity) {}

    /** @param list<class-string> $roster */
    public static function atBootstrap(array $roster, array $compiled): self
    {
        try {
            $current = new RouteParticipationCompiler()->compile($roster);
            if ($current !== $compiled) {
                throw new \UnexpectedValueException();
            }
            return new self($current['records'], $current['compiler_identity']);
        } catch (\Throwable) {
            throw new RouteCompositionException('inventory-unavailable', 'Route participation is missing, unknown or stale.');
        }
    }
}
