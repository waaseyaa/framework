<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;

/**
 * Read-only HTTP controller for the versioned-blob media version API surface.
 *
 * Exposes two endpoints:
 *   GET /api/media/{uuid}/versions         → index()
 *   GET /api/media/{uuid}/versions/{vid}   → show()
 *
 * Access control: routes are gated by `_authenticated` in BuiltinRouteRegistrar
 * (FR-008). Per-version filtering is applied inside the read-model adapter via
 * GateInterface — forbidden versions are omitted from list / map to 403 on show.
 *
 * Pattern mirrors WorkflowGuardsController (M4A-5 Phase 1): null read-model
 * yields empty/not-found shapes so installs without the media package boot cleanly.
 *
 * Binary-stream download endpoint deferred per spec (FR-010).
 *
 * Refs DIR-005 (versioned-blob-media-abstraction-01KSEFTJ).
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 */
final class MediaVersionController
{
    public function __construct(
        private readonly ?MediaVersionReadModelInterface $readModel = null,
    ) {}

    /**
     * GET /api/media/{uuid}/versions
     *
     * Returns all versions visible to the current account, newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int}}
     */
    public function index(string $uuid, Request $request): array
    {
        if ($this->readModel === null) {
            return ['data' => [], 'meta' => ['total' => 0]];
        }

        $account = $this->extractAccount($request);
        $resources = [];
        foreach ($this->readModel->findForMedia($uuid, $account) as $resource) {
            $resources[] = $resource->toArray();
        }

        return [
            'data' => $resources,
            'meta' => ['total' => count($resources)],
        ];
    }

    /**
     * GET /api/media/{uuid}/versions/{vid}
     *
     * Returns a single version resource.
     * Null read-model or unknown vid → 404.
     * Forbidden version (access policy) → 403.
     *
     * @return array{data: array<string, mixed>}
     *   |array{errors: list<array{status: string, title: string, detail: string}>, status: int}
     */
    public function show(string $uuid, int $vid, Request $request): array
    {
        if ($this->readModel === null) {
            return $this->notFound(sprintf('Media version %d not found.', $vid));
        }

        $account = $this->extractAccount($request);

        // Check if the version exists at all (unfiltered) — to distinguish 404 vs 403.
        // The adapter returns null both for "not found" and "forbidden". We issue a
        // separate raw lookup via the read-model with a guest-equivalent approach:
        // simpler to just call findByVid and let the adapter handle filtering.
        // When the version exists but is forbidden, the adapter returns null.
        // We use a two-phase approach: first check existence without the account,
        // then check access — but the adapter is the only read path.
        // Per spec: forbidden → 403, not-found → 404. We distinguish via a
        // "existence probe" using an always-allow account stub.
        $resource = $this->readModel->findByVid($uuid, $vid, $account);

        if ($resource === null) {
            // Distinguish 404 (version does not exist) from 403 (exists but forbidden).
            if ($this->readModel->existsByVid($uuid, $vid)) {
                return $this->forbidden(sprintf('Access to media version %d is forbidden.', $vid));
            }

            return $this->notFound(sprintf('Media version %d not found.', $vid));
        }

        return ['data' => $resource->toArray()];
    }

    /**
     * @return array{errors: list<array{status: string, title: string, detail: string}>, status: int}
     */
    private function notFound(string $detail): array
    {
        return [
            'status' => 404,
            'errors' => [[
                'status' => '404',
                'title' => 'Not Found',
                'detail' => $detail,
            ]],
        ];
    }

    /**
     * @return array{errors: list<array{status: string, title: string, detail: string}>, status: int}
     */
    private function forbidden(string $detail): array
    {
        return [
            'status' => 403,
            'errors' => [[
                'status' => '403',
                'title' => 'Forbidden',
                'detail' => $detail,
            ]],
        ];
    }

    private function extractAccount(Request $request): AccountInterface
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            // Fallback: anonymous. Route is _authenticated so this should never
            // happen in production; safe guard for unit-test scenarios.
            throw new \LogicException('MediaVersionController requires an authenticated account on request (_account attribute).');
        }

        return $account;
    }
}
