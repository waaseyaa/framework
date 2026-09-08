<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

/** Closed parser for the activation child's terminal protocol. @api */
final class ProjectConfigActivationResultParser
{
    public function isCompleted(\stdClass $payload, ProjectConfigAuthorization $authorization): bool
    {
        $document = (array) $payload;
        if (!$this->hasExactKeys($document, [
            'activation_sequence',
            'errors',
            'generation_id',
            'manifest_hash',
            'request_id',
            'schema',
            'status',
            'version',
        ])) {
            return false;
        }

        return $document['schema'] === 'waaseyaa.project_config_activation_result'
            && $document['version'] === 1
            && \in_array($document['status'], ['completed', 'already_completed'], true)
            && \is_string($document['request_id'])
            && preg_match('/^project-config-init-[a-f0-9]{32}$/D', $document['request_id']) === 1
            && $this->isDigest($document['generation_id'])
            && \is_int($document['activation_sequence'])
            && $document['activation_sequence'] > 0
            && \is_string($document['manifest_hash'])
            && hash_equals($authorization->bundleManifest->manifestHash, $document['manifest_hash'])
            && $document['errors'] === [];
    }

    public function failureStatus(\stdClass $payload): ?string
    {
        $document = (array) $payload;
        if (!$this->hasExactKeys($document, ['errors', 'schema', 'status', 'version'])
            || $document['schema'] !== 'waaseyaa.project_config_activation_result'
            || $document['version'] !== 1
            || !\in_array($document['status'], ['refused', 'uncertain'], true)
            || !\is_array($document['errors'])
            || $document['errors'] === []
            || !$this->validErrors($document['errors'])
        ) {
            return null;
        }

        return $document['status'];
    }

    /** @param array<string, mixed> $document @param list<string> $expected */
    private function hasExactKeys(array $document, array $expected): bool
    {
        $keys = array_keys($document);
        sort($keys, SORT_STRING);

        return $keys === $expected;
    }

    private function isDigest(mixed $value): bool
    {
        return \is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param array<mixed> $errors */
    private function validErrors(array $errors): bool
    {
        if (!array_is_list($errors)) {
            return false;
        }
        foreach ($errors as $error) {
            if ($error instanceof \stdClass) {
                $error = (array) $error;
            }
            if (!\is_array($error) || array_keys($error) !== ['message']
                || !\is_string($error['message']) || trim($error['message']) === ''
            ) {
                return false;
            }
        }

        return true;
    }
}
