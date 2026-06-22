<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\Router\SessionChannel;

/**
 * Supported read path for the caller's own Wayfinding session token (Phase 5
 * hardening, P0-2).
 *
 * The session token is the non-secret, one-way hash of the session id that the
 * SSE `connected` frame hands a connected client ({@see SessionChannel}); it is
 * what an authorized presenter uses to address a viewer's session for a live
 * trail. Before this endpoint the only way to read it was to intercept the SSE
 * wire and win the hydration race — there was no supported handle. This returns
 * the SAME value, derived server-side from the caller's session, with no SSE
 * connection required. The admin shell also surfaces it as `data-wf-session` on
 * the document root for in-page tooling.
 *
 * Returns only the CALLER's own token — the value is derived from the caller's
 * session id, so a caller can never read another session's token here.
 *
 * @api
 */
final class SessionTokenController
{
    public function show(Request $request): Response
    {
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : (string) session_id();
        $token = $sessionId === '' ? null : SessionChannel::tokenForSessionId($sessionId);

        return new Response(
            json_encode(
                [
                    'data' => [
                        'sessionToken' => $token,
                        'channel' => $token === null ? null : SessionChannel::forToken($token),
                    ],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }
}
