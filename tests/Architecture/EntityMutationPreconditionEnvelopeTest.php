<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Waaseyaa\Api\Http\EntityMutationPrecondition;

/**
 * Contract regression: If-Match mutation surfaces must share one JSON:API
 * envelope adapter. Future envelope drift (new parse sites, snake_case codes,
 * Request::getETags(), or a 412 body that can name the winner) fails here.
 */
#[CoversNothing]
final class EntityMutationPreconditionEnvelopeTest extends TestCase
{
    private const array IF_MATCH_SITES = [
        'packages/foundation/src/Http/Router/JsonApiRouter.php',
        'packages/api/src/JsonApiController.php',
        'packages/api/src/Controller/OidcClientController.php',
        'packages/api/src/Controller/TranslationController.php',
        'packages/api/src/Controller/WorkflowTransitionController.php',
        'packages/api/src/Controller/FieldAutoSaveController.php',
    ];

    private const array BODY_TOKEN_SITES = [
        'packages/admin-surface/src/Host/GenericAdminSurfaceHost.php',
    ];

    #[Test]
    public function golden_if_match_envelopes_are_owned_by_the_api_adapter(): void
    {
        $required = EntityMutationPrecondition::requiredDocument()->toArray();
        $invalid = EntityMutationPrecondition::invalidDocument(
            'If-Match must contain exactly one strong entity mutation ETag.',
        )->toArray();
        $failed = EntityMutationPrecondition::failedDocument()->toArray();

        self::assertSame([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '428',
                'title' => 'Precondition Required',
                'code' => 'MUTATION_PRECONDITION_REQUIRED',
                'detail' => EntityMutationPrecondition::REQUIRED_DETAIL,
            ]],
        ], $required);
        self::assertSame('INVALID_MUTATION_PRECONDITION', $invalid['errors'][0]['code']);
        self::assertSame('400', $invalid['errors'][0]['status']);
        self::assertSame([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '412',
                'title' => 'Precondition Failed',
                'code' => 'MUTATION_PRECONDITION_FAILED',
                'detail' => EntityMutationPrecondition::FAILED_DETAIL,
            ]],
        ], $failed);
        self::assertStringNotContainsString('emt1.', json_encode($failed, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('meta', $failed['errors'][0]);
    }

    #[Test]
    public function production_if_match_parse_sites_must_go_through_the_adapter(): void
    {
        $root = dirname(__DIR__, 2);
        $fromHttpIfMatch = [];
        $snakeCaseCodes = [];
        $bareCodes = [];

        foreach ($this->phpSources($root) as $relative => $source) {
            if (str_contains($source, 'fromHttpIfMatch(')
                && !str_ends_with($relative, 'packages/entity/src/Concurrency/EntityMutationToken.php')
                && !str_ends_with($relative, 'packages/api/src/Http/EntityMutationPrecondition.php')
            ) {
                $fromHttpIfMatch[] = $relative;
            }
            if (preg_match("/'mutation_precondition_(required|failed)'|'invalid_mutation_precondition'/", $source) === 1) {
                $snakeCaseCodes[] = $relative;
            }
            if (preg_match("/'MUTATION_PRECONDITION_(REQUIRED|FAILED)'|'INVALID_MUTATION_PRECONDITION'/", $source) === 1
                && !str_ends_with($relative, 'packages/api/src/Http/EntityMutationPrecondition.php')
            ) {
                $bareCodes[] = $relative;
            }
        }

        self::assertSame([], $fromHttpIfMatch, 'If-Match parse must wrap EntityMutationToken::fromHttpIfMatch() via EntityMutationPrecondition.');
        self::assertSame([], $snakeCaseCodes, 'If-Match surfaces must not emit snake_case mutation precondition codes.');
        self::assertSame([], $bareCodes, 'Error-code literals belong on EntityMutationPrecondition so envelope copy cannot drift.');
    }

    #[Test]
    public function if_match_sites_call_the_adapter_and_body_token_sites_do_not_switch_transport(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::IF_MATCH_SITES as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);
            self::assertStringContainsString(
                'EntityMutationPrecondition',
                $source,
                $relative . ' must use the shared If-Match envelope adapter.',
            );
            self::assertStringNotContainsString('fromOpaqueString(', $source);
            self::assertStringNotContainsString('getETags(', $source);
        }

        foreach (self::BODY_TOKEN_SITES as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);
            self::assertStringContainsString('fromOpaqueString(', $source);
            self::assertStringContainsString("payload['mutation_token']", $source);
            self::assertStringNotContainsString('fromHttpIfMatch(', $source);
            self::assertStringNotContainsString('fromRequest(', $source);
        }

        $pageBuilder = (string) file_get_contents(
            $root . '/packages/admin-surface/src/PageBuilder/GenericPageBuilderSurfaceHost.php',
        );
        self::assertStringNotContainsString('EntityMutationToken', $pageBuilder);
        self::assertStringNotContainsString('mutation_token', $pageBuilder);
        self::assertStringNotContainsString('If-Match', $pageBuilder);
    }

    /** @return iterable<string, string> */
    private function phpSources(string $root): iterable
    {
        $finder = new Finder()
            ->files()
            ->in($root . '/packages')
            ->name('*.php')
            ->exclude(['tests', 'testing', 'vendor', 'node_modules']);

        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $relative = substr($absolute, strlen($root) + 1);
            yield $relative => (string) file_get_contents($absolute);
        }
    }
}
