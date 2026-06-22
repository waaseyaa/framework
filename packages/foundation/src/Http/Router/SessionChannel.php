<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

/**
 * Reserved, per-session broadcast channel naming.
 *
 * A "private" channel is one in the reserved `session:` namespace. The broadcast
 * subscribe side ({@see BroadcastRouter}) NEVER honours a client-supplied private
 * channel — it strips them and instead auto-subscribes a connection to its OWN
 * session channel, derived server-side from the connection's PHP session id. That
 * is what enforces session isolation: a client can only ever receive its own
 * session's private messages, never another session's, regardless of the
 * `?channels=` query it sends.
 *
 * The exposed "token" is a one-way hash of the raw session id (never the raw id
 * itself, which is the auth cookie value), safe to surface to the client so an
 * authorized presenter can address that session.
 *
 * @api
 */
final class SessionChannel
{
    /** Reserved namespace prefix for private per-session channels. */
    public const string PREFIX = 'session:';

    /** Non-secret, stable token derived from a raw PHP session id (128-bit, hex). */
    public static function tokenForSessionId(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    /** The private channel for a raw PHP session id. */
    public static function forSessionId(string $sessionId): string
    {
        return self::PREFIX . self::tokenForSessionId($sessionId);
    }

    /** The private channel for an already-derived token (as exposed to clients). */
    public static function forToken(string $token): string
    {
        return self::PREFIX . $token;
    }

    /** Whether a channel name is in the reserved private namespace. */
    public static function isReserved(string $channel): bool
    {
        return str_starts_with($channel, self::PREFIX);
    }
}
