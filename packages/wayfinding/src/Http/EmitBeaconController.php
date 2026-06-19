<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;

/**
 * Wayfinding emit endpoint (Phase 2): an authenticated presenter emits a beacon
 * into a single user session over the bounded SSE broadcast loop.
 *
 * Authorization: the route requires authentication + the "present guided content"
 * capability (fail-closed); the controller re-checks the capability as
 * defence-in-depth (LD-2 / FR-003). Delivery is session-scoped (LD-1): the beacon
 * is published to the target's reserved private channel ({@see SessionChannel}),
 * which only that session's connection receives — a second session never does
 * (NFR-001). The target session is addressed by the non-secret `sessionToken` the
 * SSE `connected` frame hands the client; omitting it self-targets the caller's
 * own session. The anchor is validated against the published catalog (FR-005).
 *
 * Beacon content is transported verbatim here and escaped/constrained at render
 * time by the overlay (Phase 3, LD-4/FR-008); this phase only length-caps it.
 *
 * @api
 */
final class EmitBeaconController
{
    public const string CAPABILITY = 'present guided content';

    private const int MAX_CONTENT_LENGTH = 4000;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function emit(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        // Defence-in-depth: the route is gated (authenticated + capability), but a
        // direct dispatch must still fail closed without the capability.
        if (!$ctx->account->hasPermission(self::CAPABILITY)) {
            return $this->error(403, 'Forbidden', sprintf('The "%s" capability is required to emit beacons.', self::CAPABILITY));
        }

        $body = $ctx->parsedBody ?? [];

        $anchorId = $body['anchor_id'] ?? null;
        if (!is_string($anchorId) || $anchorId === '') {
            return $this->error(422, 'Unprocessable', 'A non-empty string "anchor_id" is required.');
        }

        $registry = new AnchorRegistry($this->entityTypeManager);
        if (!$registry->isValid($anchorId)) {
            return $this->error(422, 'Unknown anchor', sprintf('Anchor "%s" is not in the published catalog.', $anchorId));
        }

        $content = $body['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return $this->error(422, 'Unprocessable', 'A non-empty string "content" is required.');
        }
        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            return $this->error(422, 'Unprocessable', sprintf('Beacon "content" exceeds %d characters.', self::MAX_CONTENT_LENGTH));
        }

        $order = $body['order'] ?? 0;
        if (!is_int($order)) {
            return $this->error(422, 'Unprocessable', 'Beacon "order" must be an integer.');
        }

        // Address the target session by its non-secret token; default to the
        // caller's own session (self-guide). The channel is in the reserved
        // private namespace, so only that session's connection receives it.
        $token = $body['session'] ?? null;
        if (is_string($token) && $token !== '') {
            $channel = SessionChannel::forToken($token);
        } else {
            $sessionId = (string) session_id();
            if ($sessionId === '') {
                return $this->error(422, 'No target session', 'Provide a "session" token, or call from within a session to self-target.');
            }
            $channel = SessionChannel::forSessionId($sessionId);
        }

        $beacon = [
            'anchor_id' => $anchorId,
            'content' => $content,
            'order' => $order,
            'emitted_by' => $ctx->account->id(),
        ];

        $ctx->broadcastStorage->push($channel, 'wayfinding.beacon', $beacon);

        return new Response(
            json_encode(['data' => $beacon], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            202,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    private function error(int $status, string $title, string $detail): Response
    {
        return new Response(
            json_encode(
                ['errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
