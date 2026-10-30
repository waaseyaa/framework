<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Validates emitted JSON against the hand-maintained document, including refs. */
trait AgentRunOpenApiAssertions
{
    private function openApiAccepts(string $component, array $payload): bool
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2) . '/packages/api/openapi.yaml');
        $pointer = match ($component) {
            'AgentRunEvent' => '#/paths/~1broadcast/get/responses/200/content/text~1event-stream/x-agent-run-event-schema',
            'ApprovalRequest' => '#/paths/~1api~1ai~1agent~1run~1{id}~1approve/post/requestBody/content/application~1json/schema',
        };
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$ref' => $pointer,
            'paths' => $document['paths'],
            'components' => $document['components'],
        ];

        return new Validator()->validate(
            json_decode(json_encode($payload, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR),
        )->isValid();
    }
}
