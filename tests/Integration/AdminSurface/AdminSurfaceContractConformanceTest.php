<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AdminSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceUiPayload;

/**
 * Cross-boundary verification: backend-emitted admin-surface payloads
 * conform to the TypeScript contract at
 * `packages/admin-surface/contract/types.ts`.
 *
 * The audit (#842) flagged that backend DTOs and frontend behaviour were
 * tested in isolation, leaving the published host-to-SPA boundary itself
 * uncovered. This test parses the canonical TypeScript interfaces and
 * cross-validates every emitted PHP payload key against them, so drift
 * on either side breaks the test:
 *
 * - **Gap (PHP omits required field).** Every non-optional TS interface
 *   key must appear in the PHP payload.
 * - **Drift (PHP emits unknown field).** Every PHP payload key must have
 *   a corresponding TS interface field.
 *
 * Closes #842 (and protects #839 + #840).
 */
#[CoversNothing]
final class AdminSurfaceContractConformanceTest extends TestCase
{
    private const CONTRACT_TYPES_PATH = __DIR__ . '/../../../packages/admin-surface/contract/types.ts';

    /** @var array<string, array<string, bool>>|null */
    private static ?array $contractCache = null;

    #[Test]
    public function sessionPayloadConformsToAdminSurfaceSessionInterface(): void
    {
        $payload = $this->buildFullSession()->toArray();

        $this->assertConformsToInterface('AdminSurfaceSession', $payload);
        $this->assertConformsToInterface('AdminSurfaceAccount', $payload['account']);
        $this->assertConformsToInterface('AdminSurfaceTenant', $payload['tenant']);
    }

    #[Test]
    public function emailVerifiedFlowsThroughSessionPayloadAsContractField(): void
    {
        $verified = $this->buildFullSession()->toArray();
        self::assertArrayHasKey('emailVerified', $verified['account']);
        self::assertTrue($verified['account']['emailVerified']);

        $unverified = $this->buildSessionWithoutEmailVerification()->toArray();
        self::assertArrayHasKey('emailVerified', $unverified['account']);
        self::assertNull($unverified['account']['emailVerified']);
    }

    #[Test]
    public function catalogEntryConformsToAdminSurfaceCatalogEntryInterface(): void
    {
        $builder = new CatalogBuilder();
        $entity = $builder->defineEntity('node', 'Content')
            ->group('content')
            ->description('Long-form articles, blog posts, and pages.');
        $entity->field('title', 'Title', 'string')->required();
        $entity->action('publish', 'Publish');

        $entries = $builder->build();
        self::assertCount(1, $entries);

        $this->assertConformsToInterface('AdminSurfaceCatalogEntry', $entries[0]);
        $this->assertConformsToInterface('AdminSurfaceCapabilities', $entries[0]['capabilities']);
    }

    #[Test]
    public function descriptionFlowsThroughCatalogPayloadAsContractField(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('node', 'Content')->description('Editorial content.');

        $entries = $builder->build();
        self::assertSame('Editorial content.', $entries[0]['description']);
    }

    #[Test]
    public function contractParserExtractsExpectedRoster(): void
    {
        // Sanity check on the contract parser — without this guard, a parser
        // bug could silently disable conformance checking.
        $session = $this->loadInterface('AdminSurfaceSession');
        self::assertContains('account', array_keys($session));
        self::assertContains('tenant', array_keys($session));
        self::assertContains('policies', array_keys($session));
        self::assertFalse($session['features']);      // required
        self::assertFalse($session['capabilities']);  // required
        self::assertTrue($session['ui']);             // optional
        self::assertFalse($session['account']);       // required

        $account = $this->loadInterface('AdminSurfaceAccount');
        self::assertFalse($account['id']);             // required
        self::assertFalse($account['email']);          // required, nullable
        self::assertFalse($account['emailVerified']);  // required, nullable

        $entry = $this->loadInterface('AdminSurfaceCatalogEntry');
        self::assertTrue($entry['description']);  // optional
        self::assertFalse($entry['fields']);      // required
    }

    private function buildFullSession(): AdminSurfaceSessionData
    {
        return new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin', 'editor'],
            policies: ['administer content'],
            email: 'admin@example.com',
            emailVerified: true,
            tenantId: 'org-1',
            tenantName: 'Test Org',
            features: ['ai_assist' => true],
            ui: AdminSurfaceUiPayload::fromArrays(headerLinks: [], sidebarItems: []),
        );
    }

    private function buildSessionWithoutEmailVerification(): AdminSurfaceSessionData
    {
        return new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin'],
            policies: [],
            email: 'admin@example.com',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertConformsToInterface(string $interfaceName, array $payload): void
    {
        $contract = $this->loadInterface($interfaceName);

        $payloadKeys = array_keys($payload);
        $contractKeys = array_keys($contract);

        // No drift: every emitted key must be declared in the contract.
        $unknown = array_diff($payloadKeys, $contractKeys);
        self::assertSame(
            [],
            array_values($unknown),
            sprintf(
                'PHP payload emits keys not declared in TS interface %s: %s',
                $interfaceName,
                implode(', ', $unknown),
            ),
        );

        // No gap: every required (non-optional) contract key must be emitted.
        $required = array_keys(array_filter($contract, static fn(bool $optional): bool => !$optional));
        $missing = array_diff($required, $payloadKeys);
        self::assertSame(
            [],
            array_values($missing),
            sprintf(
                'PHP payload omits required keys from TS interface %s: %s',
                $interfaceName,
                implode(', ', $missing),
            ),
        );
    }

    /**
     * @return array<string, bool>  field name → optional?
     */
    private function loadInterface(string $name): array
    {
        if (self::$contractCache === null) {
            self::$contractCache = $this->parseContractFile();
        }

        self::assertArrayHasKey(
            $name,
            self::$contractCache,
            sprintf('TS interface %s not found in contract types.ts', $name),
        );

        return self::$contractCache[$name];
    }

    /**
     * Lightweight regex parser for `packages/admin-surface/contract/types.ts`.
     *
     * Extracts every `export interface X { ... }` block and maps each field
     * name to a boolean: true if the field is optional (declared with `?:`),
     * false if it is required (declared with `:`). Comment lines and JSDoc
     * blocks are ignored.
     *
     * Tightly coupled to the simple shape of the current contract file.
     * If contract style evolves (intersection types, generics, etc.),
     * extend this parser rather than weakening the conformance assertions.
     *
     * @return array<string, array<string, bool>>
     */
    private function parseContractFile(): array
    {
        $source = file_get_contents(self::CONTRACT_TYPES_PATH);
        self::assertNotFalse($source, 'Failed to read admin-surface contract types.ts');

        $interfaces = [];
        $pattern = '/export\s+interface\s+(\w+)\s*\{([^}]*)\}/s';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
            self::fail('Regex failure parsing admin-surface contract');
        }

        foreach ($matches as $match) {
            $interfaceName = $match[1];
            $body = $match[2];
            $fields = [];

            foreach (explode("\n", $body) as $line) {
                $stripped = trim($line);
                // Skip blank lines, single-line comments, and JSDoc fragments.
                if (
                    $stripped === ''
                    || str_starts_with($stripped, '//')
                    || str_starts_with($stripped, '*')
                    || str_starts_with($stripped, '/*')
                ) {
                    continue;
                }

                if (preg_match('/^(\w+)(\??):/', $stripped, $fieldMatch) === 1) {
                    $fields[$fieldMatch[1]] = $fieldMatch[2] === '?';
                }
            }

            $interfaces[$interfaceName] = $fields;
        }

        return $interfaces;
    }
}
