<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\AdminSurface\Query\SurfaceQueryParser;

/**
 * Base host class that applications extend to integrate with the admin SPA.
 *
 * Each application provides its own subclass that implements the abstract
 * methods to resolve sessions, build catalogs, and handle entity operations.
 *
 * Maps to the backend side of AdminSurfaceContract in contract/AdminSurfaceContract.ts.
 */
abstract class AbstractAdminSurfaceHost
{
    /**
     * Resolve the current admin session from the request.
     *
     * Returns null if the request is not authenticated or not authorized
     * for admin access.
     */
    abstract public function resolveSession(Request $request): ?AdminSurfaceSessionData;

    /**
     * Build the entity catalog for the authenticated session.
     *
     * Use the CatalogBuilder to declaratively define available entity types,
     * their fields, actions, and capabilities.
     */
    abstract public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder;

    /**
     * List entities of the given type.
     *
     * @param string                    $type    Entity type ID
     * @param SurfaceQuery|array<string, mixed> $query   Filter/sort/pagination parameters
     */
    abstract public function list(string $type, SurfaceQuery|array $query = []): AdminSurfaceResultData;

    /**
     * Get a single entity by type and ID.
     */
    abstract public function get(string $type, string $id): AdminSurfaceResultData;

    /**
     * Execute a named action on an entity type.
     *
     * @param string               $type    Entity type ID
     * @param string               $action  Action identifier
     * @param array<string, mixed> $payload Action payload
     */
    abstract public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData;

    /**
     * Handle the session endpoint.
     *
     * @return array<string, mixed>
     */
    public function handleSession(Request $request): array
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        return AdminSurfaceResultData::success($session->toArray())->toArray();
    }

    /**
     * Handle the catalog endpoint.
     *
     * @return array<string, mixed>
     */
    public function handleCatalog(Request $request): array
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        $catalog = $this->buildCatalog($session);

        return AdminSurfaceResultData::success([
            'entities' => $catalog->build(),
        ])->toArray();
    }

    /**
     * Handle the list endpoint.
     *
     * @return array<string, mixed>
     */
    public function handleList(Request $request, string $type): array
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        return $this->list($type, SurfaceQueryParser::fromRequest($request))->toArray();
    }

    /**
     * Handle the get endpoint.
     *
     * @return array<string, mixed>
     */
    public function handleGet(Request $request, string $type, string $id): array
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        return $this->get($type, $id)->toArray();
    }

    /**
     * Handle the action endpoint.
     *
     * @return array<string, mixed>
     */
    public function handleAction(Request $request, string $type, string $action): array
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        $content = $request->getContent();
        $payload = $content !== '' ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];

        return $this->action($type, $action, $payload ?? [])->toArray();
    }
}
