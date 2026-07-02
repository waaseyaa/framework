<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Symfony\Component\HttpFoundation\Response;

/**
 * HTML shown when the Nuxt admin SPA has not been built into public/admin/.
 *
 * Owned by admin-surface so apps do not duplicate fallback copy. Prefix /admin/_surface
 * is fixed (not configurable).
 */
final class AdminSpaFallback
{
    public const SPEC_URL = 'https://github.com/waaseyaa/framework/blob/main/docs/specs/admin-spa.md';

    public static function htmlResponse(string $appDisplayName = 'Application'): Response
    {
        $title = htmlspecialchars($appDisplayName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $spec = htmlspecialchars(self::SPEC_URL, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pSession = htmlspecialchars(AdminSurfaceRoutePaths::PATH_SESSION, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pCatalog = htmlspecialchars(AdminSurfaceRoutePaths::PATH_CATALOG, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pList = htmlspecialchars(AdminSurfaceRoutePaths::PATH_LIST, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pGet = htmlspecialchars(AdminSurfaceRoutePaths::PATH_GET, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pAction = htmlspecialchars(AdminSurfaceRoutePaths::PATH_ACTION, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $html = <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Admin — {$title}</title>
                <style>
                    body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem; }
                    h1 { font-size: 1.5rem; }
                    code { background: #f3f4f6; padding: 0.2em 0.4em; border-radius: 4px; font-size: 0.9em; }
                    .endpoints { margin-block: 1rem; }
                    .endpoints dt { font-weight: 600; margin-block-start: 0.5rem; }
                    .endpoints dd { margin-inline-start: 1rem; color: #6b7280; }
                    a { color: #2563eb; }
                </style>
            </head>
            <body>
                <h1>{$title} — Admin</h1>
                <p>The admin SPA is not built yet (no <code>public/admin/index.html</code>). The admin surface API is available:</p>
                <dl class="endpoints">
                    <dt>GET {$pSession}</dt>
                    <dd>Session resolution (requires session)</dd>
                    <dt>GET {$pCatalog}</dt>
                    <dd>Entity type catalog</dd>
                    <dt>GET {$pList}</dt>
                    <dd>Entity listing</dd>
                    <dt>GET {$pGet}</dt>
                    <dd>Entity detail</dd>
                    <dt>POST {$pAction}</dt>
                    <dd>Action dispatch</dd>
                </dl>
                <p>Developer UI (optional): run <code>composer run dev</code> from the app, or see the <a href="{$spec}">Admin SPA spec</a>.</p>
                <p>See all commands: <code>bin/waaseyaa list</code></p>
            </body>
            </html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
