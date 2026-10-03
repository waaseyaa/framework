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
    private ?RouteSnapshot $snapshot = null;
    private ?RouteCompositionException $failure = null;

    public function __construct(private readonly ValidatedRouteParticipation $participation, private readonly string $profile) {}

    public function state(): string
    {
        return $this->state;
    }

    public function ready(array $contributors, array $contexts): void
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
        $this->contributors = $admittedContributors;
        $this->contexts = $admittedContexts;
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
            $routes = [];
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
            $this->requireCollecting();
            $snapshot = new RouteSnapshot($routes, ['participation' => $this->participation->records, 'compiler' => $this->participation->compilerIdentity, 'contexts' => $inputs]);
        } catch (\Throwable) {
            $this->fail($this->failure ?? new RouteCompositionException('contribution-failed', 'Route contribution failed; create a new boot epoch to retry.'));
        }
        $this->snapshot = $snapshot;
        $this->state = 'complete';
        $this->contributors = [];
        $this->contexts = [];
        return $snapshot;
    }

    private function fail(RouteCompositionException $failure): never
    {
        $this->state = 'failed';
        $this->failure = $failure;
        $this->contributors = [];
        $this->contexts = [];
        throw $failure;
    }

    /** Recheck lifecycle after contributor execution, which can reenter the epoch. */
    private function requireCollecting(): void
    {
        if ($this->state !== 'collecting') {
            throw $this->failure ?? new RouteCompositionException('unavailable', 'Route publication requires an active collection.');
        }
    }
}
