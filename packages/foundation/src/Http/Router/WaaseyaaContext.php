<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\Api\Controller\BroadcastStorage;

/**
 * Typed, validated view of raw Request attributes.
 *
 * Built once by HttpKernel, stored as `_waaseyaa_context` on the Request.
 * Routers read this directly instead of parsing attributes individually.
 */
final class WaaseyaaContext
{
    /**
     * @param ?array<string, mixed> $parsedBody
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly AccountInterface $account,
        public readonly AuthorizationPrincipalInterface $principal,
        public readonly ?array $parsedBody,
        public readonly array $query,
        public readonly string $method,
        public readonly BroadcastStorage $broadcastStorage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $account = $request->attributes->get('_account');
        $principal = DecisionAccountResolver::resolve(
            $request->attributes->get('_authorization_principal'),
            $account,
        );
        if (!$account instanceof AccountInterface || $principal === null) {
            throw new \LogicException('WaaseyaaContext requires a validated immutable decision account.');
        }

        return new self(
            account: $account,
            principal: $principal,
            parsedBody: $request->attributes->get('_parsed_body'),
            query: $request->query->all(),
            method: $request->getMethod(),
            broadcastStorage: $request->attributes->get('_broadcast_storage'),
        );
    }
}
