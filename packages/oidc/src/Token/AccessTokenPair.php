<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

/**
 * Value object returned by AccessTokenIssuer::issue().
 *
 * @api
 */
final readonly class AccessTokenPair
{
    public function __construct(
        /** UUID used as the jti claim and storage primary key. */
        public string $jti,
        /** Opaque URL-safe token value sent to the client. */
        public string $token,
        /** Lifetime in seconds. */
        public int $expiresIn,
    ) {}
}
