<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;

/** Atomic, terminal composition lifecycle for one finalized boot profile. @api */
final class RouteCompositionEpoch
{
    private string $state = 'unavailable';
    private bool $ready = false;
    private array $contributors = [];
    private array $contexts = [];
    /** @var list<RouteDefinition> */
    private array $builtins = [];
    /** @var list<RouteDefinition> */
    private array $terminal = [];
    private array $declarationInputs = [];
    private ?RouteSnapshot $snapshot = null;
    private ?RouteCompositionException $failure = null;

    public function __construct(private readonly ValidatedRouteParticipation $participation, private readonly string $profile) {}

    public function state(): string
    {
        return $this->state;
    }

    /**
     * Freeze one finalized source set. Kernel completeness remains the caller's contract.
     *
     * @param array<array-key, mixed> $builtins Validated into the built-in declaration list.
     * @param array<array-key, mixed> $terminal Validated into the terminal declaration list.
     * @param array<array-key, mixed> $declarationInputs Explicitly selected non-secret inputs, never full application config.
     */
    public function ready(array $contributors, array $contexts, array $builtins = [], array $terminal = [], array $declarationInputs = []): void
    {
        if (!in_array($this->profile, ['cli', 'http'], true)) {
            throw new RouteCompositionException('unsupported-profile', 'This boot profile cannot compose runtime routes.');
        }
        if ($this->ready || $this->state !== 'unavailable') {
            throw new RouteCompositionException('unavailable', 'Route composition readiness is already finalized.');
        }
        $admittedContributors = [];
        $admittedContexts = [];
        foreach ($this->participation->records as $order => $record) {
            if ($record['kind'] !== 'declarative') {
                continue;
            }
            $id = $record['provider'];
            $provider = $contributors[$id] ?? null;
            $context = $contexts[$id] ?? null;
            if (!$provider instanceof ContributesRouteMetadataInterface || $provider::class !== $id
                || !$context instanceof RouteContributionContext || $context->sourceId !== $id || $context->sourceOrder !== $order) {
                throw new RouteCompositionException('unavailable', 'Route contribution inputs do not match the admitted roster.');
            }
            $admittedContributors[$id] = $provider;
            $admittedContexts[$id] = $context;
        }
        $admittedBuiltins = $this->staticSource($builtins, 'foundation.builtin');
        $admittedTerminal = $this->staticSource($terminal, 'foundation.terminal');
        try {
            $admittedInputs = ScalarRouteMetadata::copy($declarationInputs);
        } catch (\Throwable) {
            throw new RouteCompositionException('unavailable', 'Finalized route declaration inputs must be scalar metadata.');
        }
        $this->contributors = $admittedContributors;
        $this->contexts = $admittedContexts;
        $this->builtins = $admittedBuiltins;
        $this->terminal = $admittedTerminal;
        $this->declarationInputs = $admittedInputs;
        $this->ready = true;
    }

    public function snapshot(): RouteSnapshot
    {
        if ($this->state === 'complete') {
            return $this->snapshot;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->state === 'collecting') {
            $this->fail(new RouteCompositionException('collecting', 'Recursive route composition is unavailable.'));
        }
        if (!$this->ready) {
            throw new RouteCompositionException('unavailable', 'Route contribution inputs are not finalized for profile ' . $this->profile . '.');
        }
        foreach ($this->participation->records as $record) {
            if ($record['kind'] === 'legacy') {
                $this->fail(new RouteCompositionException('legacy-contributor', 'Legacy route contributor: ' . $record['provider']));
            }
        }
        $this->state = 'collecting';
        try {
            $routes = $this->builtins;
            $inputs = [];
            foreach ($this->participation->records as $record) {
                if ($record['kind'] !== 'declarative') {
                    continue;
                }
                $id = $record['provider'];
                $context = $this->contexts[$id];
                $inputs[$id] = ['configuration' => $context->configuration, 'capabilities' => $context->capabilities, 'entities' => $context->entities];
                $ordinal = 0;
                foreach ($this->contributors[$id]->routeDefinitions($context) as $route) {
                    if (!$route instanceof RouteDefinition || $route->sourceId !== $id || $route->ordinal !== $ordinal++) {
                        throw new \UnexpectedValueException();
                    }
                    $routes[] = $route;
                }
            }
            array_push($routes, ...$this->terminal);
            $this->requireCollecting();
            // PHP's stable sort preserves collection order for equal priorities.
            usort($routes, static fn(RouteDefinition $left, RouteDefinition $right): int => $right->priority <=> $left->priority);
            $snapshot = new RouteSnapshot($routes, [
                'participation' => $this->participation->records,
                'compiler' => $this->participation->compilerIdentity,
                'sources' => ['foundation.builtin', ...array_column($this->participation->records, 'provider'), 'foundation.terminal'],
                'declarations' => $this->declarationInputs,
                'contexts' => $inputs,
            ]);
        } catch (\Throwable) {
            $this->fail($this->failure ?? new RouteCompositionException('contribution-failed', 'Route contribution failed; create a new boot epoch to retry.'));
        }
        $this->snapshot = $snapshot;
        $this->state = 'complete';
        $this->contributors = [];
        $this->contexts = [];
        $this->builtins = [];
        $this->terminal = [];
        $this->declarationInputs = [];
        return $snapshot;
    }

    private function fail(RouteCompositionException $failure): never
    {
        $this->state = 'failed';
        $this->failure = $failure;
        $this->contributors = [];
        $this->contexts = [];
        $this->builtins = [];
        $this->terminal = [];
        $this->declarationInputs = [];
        throw $failure;
    }

    /** @return list<RouteDefinition> */
    private function staticSource(array $routes, string $source): array
    {
        if (!array_is_list($routes)) {
            throw new RouteCompositionException('unavailable', 'Static route declarations must be ordered lists.');
        }
        $admitted = [];
        foreach ($routes as $ordinal => $route) {
            if (!$route instanceof RouteDefinition || $route->sourceId !== $source || $route->ordinal !== $ordinal) {
                throw new RouteCompositionException('unavailable', 'Static route declarations do not match their source.');
            }
            $admitted[] = $route;
        }
        return $admitted;
    }

    /** Recheck lifecycle after contributor execution, which can reenter the epoch. */
    private function requireCollecting(): void
    {
        if ($this->state !== 'collecting') {
            throw $this->failure ?? new RouteCompositionException('unavailable', 'Route publication requires an active collection.');
        }
    }
}
