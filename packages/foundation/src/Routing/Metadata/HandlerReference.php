<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Stable execution identity; constructing it never loads or resolves a handler. @api */
final readonly class HandlerReference
{
    private const array BUILTINS = [
        'openapi', 'entity_types', 'entity_type.disable', 'entity_type.enable',
        'broadcast', 'media.upload', 'media.download', 'media.view',
        'attachment.download', 'search.semantic', 'discovery.topic_hub',
        'discovery.cluster', 'discovery.timeline', 'discovery.endpoint', 'render.page',
    ];

    private function __construct(public string $id, public string $kind, public string $target, public ?string $method) {}

    public static function fromString(string $id): self
    {
        if (str_starts_with($id, 'builtin:')) {
            $target = substr($id, 8);
            if (!in_array($target, self::BUILTINS, true)) {
                throw new \InvalidArgumentException('Unknown built-in route handler.');
            }
            return new self($id, 'builtin', $target, null);
        }
        if (preg_match('/^(service|class):([^:]+)::([a-zA-Z_][a-zA-Z0-9_]*)$/D', $id, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid route handler reference.');
        }
        [$unused, $kind, $target, $method] = $matches;
        if ($kind === 'class') {
            if (preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\\\\)*[a-zA-Z_][a-zA-Z0-9_]*$/D', $target) !== 1) {
                throw new \InvalidArgumentException('Invalid route handler class identifier.');
            }
        } else {
            ScalarRouteMetadata::identifier($target);
        }
        return new self($id, $kind, $target, $method);
    }
}
