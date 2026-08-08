<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http\AppController;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Routing\Redirector;

/**
 * @api
 */
final readonly class AppInvocationContext
{
    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $query
     */
    public function __construct(
        public Request $request,
        public Route $route,
        public AccountInterface $account,
        public EntityTypeManagerInterface $entityTypeManager,
        public Environment $twig,
        public array $routeParams,
        public array $query,
        public ?\Waaseyaa\Access\Gate\GateInterface $gate,
        public ?HttpServiceResolverInterface $serviceResolver,
        public ?AccountInterface $decisionAccount = null,
        public ?Redirector $redirector = null,
    ) {}
}
