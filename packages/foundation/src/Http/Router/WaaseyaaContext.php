<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;

final class WaaseyaaContext
{
    /**
     * @param ?array<string, mixed> $parsedBody
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly AccountInterface $account,
        public readonly ?array $parsedBody,
        public readonly array $query,
        public readonly string $method,
        public readonly BroadcastStorage $broadcastStorage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            account: $request->attributes->get('_account'),
            parsedBody: $request->attributes->get('_parsed_body'),
            query: $request->query->all(),
            method: $request->getMethod(),
            broadcastStorage: $request->attributes->get('_broadcast_storage'),
        );
    }
}
