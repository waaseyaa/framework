<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\Api\JsonApiError;

#[CoversClass(AdminSurfaceResultData::class)]
final class AdminSurfaceResultDataTest extends TestCase
{
    #[Test]
    public function successWithData(): void
    {
        $result = AdminSurfaceResultData::success(['name' => 'Test']);

        $array = $result->toArray();

        self::assertTrue($array['ok']);
        self::assertSame(['name' => 'Test'], $array['data']);
        self::assertArrayNotHasKey('error', $array);
    }

    #[Test]
    public function successWithMeta(): void
    {
        $result = AdminSurfaceResultData::success(
            ['id' => '1'],
            ['total' => 42],
        );

        $array = $result->toArray();

        self::assertTrue($array['ok']);
        self::assertSame(['total' => 42], $array['meta']);
    }

    #[Test]
    public function errorWithStatusAndTitle(): void
    {
        $result = AdminSurfaceResultData::error(404, 'Not Found');

        $array = $result->toArray();

        self::assertFalse($array['ok']);
        self::assertSame(404, $array['error']['status']);
        self::assertSame('Not Found', $array['error']['title']);
        self::assertArrayNotHasKey('detail', $array['error']);
        self::assertArrayNotHasKey('data', $array);
    }

    #[Test]
    public function errorWithDetail(): void
    {
        $result = AdminSurfaceResultData::error(
            422,
            'Validation Failed',
            'The title field is required.',
        );

        $array = $result->toArray();

        self::assertFalse($array['ok']);
        self::assertSame('Validation Failed', $array['error']['title']);
        self::assertSame('The title field is required.', $array['error']['detail']);
        self::assertArrayNotHasKey('code', $array['error']);
        self::assertArrayNotHasKey('source', $array['error']);
        self::assertArrayNotHasKey('meta', $array['error']);
    }

    #[Test]
    public function json_api_error_meta_is_projected_to_the_advisory_allowlist_only(): void
    {
        $acknowledgement = str_repeat('a', 64);
        $error = new JsonApiError(
            status: '428',
            title: 'Precondition Required',
            detail: 'Review and acknowledge the save advisory before retrying.',
            code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            source: ['pointer' => '/data/attributes/title'],
            meta: [
                'save_advisories' => [[
                    'code' => 'RESERVED_ROUTE_VALUE',
                    'field' => 'title',
                    'severity' => 'warning',
                    'message' => 'The short route is reserved; use /pages/news.',
                    'acknowledgement' => $acknowledgement,
                    'entity_id' => '7',
                    'policy_reason' => 'internal.policy.reason',
                    'nested' => ['token' => 'should-not-cross'],
                ]],
                'reason' => 'policy.internal.reason',
                'entity_id' => 'internal-id-7',
                'token' => 'session-secret',
                'unexpected' => ['arbitrary' => true],
            ],
        );

        $array = AdminSurfaceResultData::fromJsonApiError($error, 500)->toArray();

        self::assertFalse($array['ok']);
        self::assertSame([
            'status' => 428,
            'title' => 'Precondition Required',
            'detail' => 'Review and acknowledge the save advisory before retrying.',
            'code' => 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
            'meta' => [
                'save_advisories' => [[
                    'code' => 'RESERVED_ROUTE_VALUE',
                    'field' => 'title',
                    'severity' => 'warning',
                    'message' => 'The short route is reserved; use /pages/news.',
                    'acknowledgement' => $acknowledgement,
                ]],
            ],
        ], $array['error']);
    }

    #[Test]
    public function non_advisory_json_api_errors_keep_the_legacy_status_title_detail_envelope(): void
    {
        $error = JsonApiError::forbidden(
            'Account lacks the required permission.',
            code: 'WORKFLOW_TRANSITION_DENIED',
            meta: [
                'reason' => 'permission',
                'policy_reason' => 'internal.policy.reason',
                'entity_id' => '7',
                'token' => 'session-secret',
                'nested' => ['arbitrary' => true],
            ],
        );

        $array = AdminSurfaceResultData::fromJsonApiError($error, 500)->toArray();

        self::assertSame(403, $array['error']['status']);
        self::assertSame('Forbidden', $array['error']['title']);
        self::assertSame('Account lacks the required permission.', $array['error']['detail']);
        self::assertArrayNotHasKey('code', $array['error']);
        self::assertArrayNotHasKey('source', $array['error']);
        self::assertArrayNotHasKey('meta', $array['error']);
    }
}
