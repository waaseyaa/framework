<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/** Canonical permission identifiers and definitions for workflow transitions. @api */
final class WorkflowPermissions
{
    public static function transition(string $workflowId, string $transitionId, string $explicitPermission = ''): string
    {
        self::subject($workflowId, 'workflow');
        self::subject($transitionId, 'transition');
        if ($explicitPermission !== '') {
            if ($explicitPermission !== trim($explicitPermission) || preg_match('/[\x00-\x1F\x7F]/', $explicitPermission) === 1) {
                throw new \InvalidArgumentException('Explicit workflow permission ids must be non-padded and contain no control characters.');
            }

            return $explicitPermission;
        }

        return "use $workflowId transition $transitionId";
    }

    /**
     * Transitions may be keyed definitions, keyed explicit permission strings,
     * or a list of definitions carrying an `id` member.
     *
     * @param iterable<int|string, mixed> $transitions
     * @return array<string, array{title: string, description: string}>
     */
    public static function forWorkflow(string $workflowId, iterable $transitions): array
    {
        self::subject($workflowId, 'workflow');
        $definitions = [];
        foreach ($transitions as $key => $transition) {
            $id = is_string($key) ? $key : (is_array($transition) && is_string($transition['id'] ?? null) ? $transition['id'] : '');
            self::subject($id, 'transition');
            $explicit = is_string($transition)
                ? $transition
                : (is_array($transition) && is_string($transition['permission'] ?? null) ? $transition['permission'] : '');
            $permission = self::transition($workflowId, $id, $explicit);
            if (isset($definitions[$permission])) {
                throw new \InvalidArgumentException(sprintf('Workflow "%s" contributes permission "%s" more than once.', $workflowId, $permission));
            }
            $label = is_array($transition) && is_string($transition['label'] ?? null) && trim($transition['label']) !== ''
                ? trim($transition['label'])
                : ucfirst(str_replace(['_', '-'], ' ', $id));
            $definitions[$permission] = [
                'title' => $label . ' transition',
                'description' => sprintf('Use the %s transition on the %s workflow.', $id, $workflowId),
            ];
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    /** @return array<string, array{title: string, description: string}> */
    public static function defaultEditorial(): array
    {
        return self::forWorkflow('editorial', DefaultWorkflows::EDITORIAL['transitions']);
    }

    private static function subject(string $subject, string $kind): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $subject) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid workflow permission %s id "%s".', $kind, $subject));
        }
    }
}
