<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\AuthoringRoleMatrix;

#[CoversClass(AuthoringRoleMatrix::class)]
final class AuthoringRoleMatrixTest extends TestCase
{
    #[Test]
    public function matrix_expands_bundle_templates(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article', 'page'],
            roles: [
                'contributor' => [
                    'label' => 'Contributor',
                    'permissions' => ['access content'],
                    'bundle_permissions' => ['create {bundle} content'],
                ],
            ],
        );

        $result = $matrix->matrix();

        self::assertSame(['Contributor'], array_values(array_map(static fn(array $r): string => $r['label'], $result)));
        self::assertContains('access content', $result['contributor']['permissions']);
        self::assertContains('create article content', $result['contributor']['permissions']);
        self::assertContains('create page content', $result['contributor']['permissions']);
    }

    #[Test]
    public function permissions_for_roles_merges_and_sorts(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [
                'editor' => [
                    'label' => 'Editor',
                    'permissions' => ['administer nodes'],
                    'bundle_permissions' => ['publish {bundle} content'],
                ],
                'reviewer' => [
                    'label' => 'Reviewer',
                    'bundle_permissions' => ['edit any {bundle} content'],
                ],
            ],
        );

        $permissions = $matrix->permissionsForRoles(['editor', 'reviewer']);

        self::assertSame([
            'administer nodes',
            'edit any article content',
            'publish article content',
        ], $permissions);
    }

    #[Test]
    public function snapshot_is_empty_when_no_workflow_guards_supplied(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: ['contributor' => ['label' => 'Contributor']],
        );

        self::assertSame([], $matrix->snapshot());
        self::assertSame([], $matrix->forWorkflow('editorial'));
        self::assertSame([], $matrix->knownWorkflowIds());
    }

    #[Test]
    public function for_workflow_returns_empty_list_for_unknown_id(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'editorial' => ['publish' => ['editor', 'administrator']],
            ],
        );

        self::assertSame([], $matrix->forWorkflow('unknown_workflow'));
    }

    #[Test]
    public function for_workflow_emits_one_row_per_bundle_transition_pair(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['page', 'article'],
            roles: [],
            workflowGuards: [
                'editorial' => [
                    'publish' => ['editor', 'administrator'],
                    'archive' => ['editor', 'administrator'],
                ],
            ],
        );

        $rows = $matrix->forWorkflow('editorial');

        // 2 bundles × 2 transitions = 4 rows, ordered by bundle then transition.
        self::assertCount(4, $rows);
        self::assertSame(
            [
                ['bundle' => 'article', 'transition' => 'archive', 'required_roles' => ['editor', 'administrator']],
                ['bundle' => 'article', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
                ['bundle' => 'page', 'transition' => 'archive', 'required_roles' => ['editor', 'administrator']],
                ['bundle' => 'page', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
            ],
            $rows,
        );
    }

    #[Test]
    public function snapshot_flattens_all_workflows_with_stable_ordering(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'release' => ['ship' => ['editor']],
                'editorial' => [
                    'publish' => ['editor', 'administrator'],
                    'archive' => ['administrator'],
                ],
            ],
        );

        $snapshot = $matrix->snapshot();

        self::assertSame(
            [
                ['workflow_id' => 'editorial', 'bundle' => 'article', 'transition' => 'archive', 'required_roles' => ['administrator']],
                ['workflow_id' => 'editorial', 'bundle' => 'article', 'transition' => 'publish', 'required_roles' => ['editor', 'administrator']],
                ['workflow_id' => 'release',   'bundle' => 'article', 'transition' => 'ship',    'required_roles' => ['editor']],
            ],
            $snapshot,
        );
    }

    #[Test]
    public function snapshot_emits_no_rows_when_no_bundles_configured(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: [],
            roles: [],
            workflowGuards: [
                'editorial' => ['publish' => ['editor']],
            ],
        );

        self::assertSame([], $matrix->snapshot());
        self::assertSame([], $matrix->forWorkflow('editorial'));
        // The workflow is still known to the matrix, even when no bundles produce rows.
        self::assertSame(['editorial'], $matrix->knownWorkflowIds());
    }

    #[Test]
    public function known_workflow_ids_is_sorted(): void
    {
        $matrix = new AuthoringRoleMatrix(
            bundles: ['article'],
            roles: [],
            workflowGuards: [
                'release' => ['ship' => ['editor']],
                'editorial' => ['publish' => ['editor']],
            ],
        );

        self::assertSame(['editorial', 'release'], $matrix->knownWorkflowIds());
    }
}
