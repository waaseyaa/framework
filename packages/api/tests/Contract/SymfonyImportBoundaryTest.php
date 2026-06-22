<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Waaseyaa\Api\Http\JsonApiResponse;
use Waaseyaa\Api\Tests\Fixtures\SymfonyImportBoundary\SymfonyFreeController;

/**
 * Executable contract for the Path R-narrow boundary defined by mission
 * 1107-api-symfony-decoupling.
 *
 * Asserts two complementary things:
 *
 * 1. **Source-level boundary.** The `SymfonyFreeController` fixture file
 *    contains no `use Symfony\…` line. App code can author a JSON:API
 *    controller that depends only on Waaseyaa-owned framework names.
 * 2. **Behavioral boundary.** Constructed end-to-end, the same fixture
 *    produces a `Waaseyaa\Api\Http\JsonApiResponse` with the canonical
 *    JSON:API content type and a faithful payload — proving the
 *    Symfony-free path is not just lexical but actually wired through.
 *
 * The `bin/check-symfony-imports` linter (ratified C-005 (b)) is the
 * primary, repo-wide enforcement of this boundary and runs in CI
 * (`run_gate check-symfony-imports`) and `composer verify`. This test
 * complements the linter with a positive, executable example: it proves
 * the Symfony-free path is not merely lexical but actually wires through
 * to a canonical JSON:API response.
 */
#[CoversNothing]
final class SymfonyImportBoundaryTest extends TestCase
{
    private const FIXTURE_FILE = __DIR__ . '/../Fixtures/SymfonyImportBoundary/SymfonyFreeController.php';

    #[Test]
    public function fixture_controller_imports_no_symfony_namespace(): void
    {
        $source = file_get_contents(self::FIXTURE_FILE);

        $this->assertNotFalse($source, 'Fixture controller source must be readable');
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*use\s+Symfony\\\\/m',
            $source,
            'SymfonyFreeController must not import any Symfony namespace — '
            . 'it is the executable contract for mission 1107 Path R-narrow.',
        );
    }

    #[Test]
    public function fixture_controller_produces_jsonapi_response(): void
    {
        $controller = new SymfonyFreeController();

        // Waaseyaa\Foundation\Http\Request is a class_alias of Symfony's
        // Request (per ratified C-002). The test wires a real Symfony Request
        // here because the runtime instance is the same — the assertion is
        // about the *fixture's* type hints, not this test's.
        $request = SymfonyRequest::create('/symfony-free/42', 'GET');

        $response = $controller->show($request, '42');

        $this->assertInstanceOf(JsonApiResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.api+json',
            $response->headers->get('Content-Type'),
        );

        $decoded = json_decode((string) $response->getContent(), true);
        $this->assertSame(
            [
                'data' => [
                    'id' => '42',
                    'type' => 'symfony_free_resource',
                    'attributes' => [
                        'method' => 'GET',
                    ],
                ],
            ],
            $decoded,
        );
    }
}
