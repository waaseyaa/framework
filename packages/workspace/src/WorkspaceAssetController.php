<?php

declare(strict_types=1);

namespace Waaseyaa\Workspace;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the workspace package's client assets (the shared chat client, the
 * markdown renderer, and their stylesheet) from the package's assets/ dir.
 *
 * Apps reference them as /workspace/assets/<file>; an app can shadow any of
 * them by shipping its own file at public/workspace/assets/<file>, because a
 * real file on disk wins over a route in every Waaseyaa front controller.
 *
 * @api Invoked by route string (workspace.asset) — reflection-invisible to
 *      the dead-code gate.
 */
final class WorkspaceAssetController
{
    /** Files the route will serve: a strict allowlist, not a directory listing. */
    private const FILES = [
        'workspace-chat.js' => 'application/javascript',
        'workspace-md.js' => 'application/javascript',
        'workspace-chat.css' => 'text/css',
    ];

    public function serve(string $file): Response
    {
        if (!isset(self::FILES[$file])) {
            return new Response('Not found.', 404, ['Content-Type' => 'text/plain']);
        }

        $path = self::assetsDir() . '/' . $file;
        if (!is_file($path)) {
            return new Response('Not found.', 404, ['Content-Type' => 'text/plain']);
        }

        // NOTE: on authenticated requests the kernel's render-cache layer
        // overwrites Cache-Control with "private, no-store" (CacheConfigResolver),
        // so this header only takes effect for anonymous hits. Production apps
        // that want browser caching for signed-in users should ship shadow
        // copies at public/workspace/assets/ — a real file bypasses PHP.
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public static function assetsDir(): string
    {
        return dirname(__DIR__) . '/assets';
    }
}
