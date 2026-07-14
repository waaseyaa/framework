<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Contract\Bimaaji;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool;
use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;

#[CoversClass(GeneratePatchTool::class)]
final class GeneratePatchToolTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/m2-wp03-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                @unlink($this->tempDir . '/' . $entry);
            }
            @rmdir($this->tempDir);
        }
    }

    #[Test]
    public function delegatesToPatchGenerator(): void // FR-004
    {
        $tool = $this->makeTool();
        $result = $tool->execute(
            $this->validArguments(),
            $this->accountWithPermission('bimaaji.mutate'),
        );

        self::assertFalse($result->isError);
        $payload = $result->content[0]['data'] ?? null;
        self::assertIsArray($payload);
        self::assertArrayHasKey('patches', $payload);
        self::assertCount(1, $payload['patches'], 'add_field on a known entity must yield exactly one patch.');

        $patch = $payload['patches'][0];
        self::assertSame('src/Entity/fields/user/nickname.php', $patch['file_path'] ?? null);
        self::assertArrayHasKey('content_hash', $patch);
        self::assertSame(hash('sha256', $patch['content']), $patch['content_hash']);
    }

    /**
     * SC-005 — patch generation must never write to the filesystem.
     *
     * Two checks: (1) the temp directory the test sets up sees no incidental
     * writes from any code path the tool exercises; (2) the path advertised
     * in the returned PatchSet does not actually exist on disk. The second
     * check is the killer assertion — if anyone wires real filesystem writes
     * into PatchGenerator, this test fails on the exact path the tool
     * promised to emit.
     */
    #[Test]
    public function doesNotWriteToFilesystem(): void // SC-005
    {
        $beforeSnapshot = $this->snapshotDirectory($this->tempDir);

        $tool = $this->makeTool();
        $result = $tool->execute(
            $this->validArguments(),
            $this->accountWithPermission('bimaaji.mutate'),
        );

        self::assertFalse($result->isError);
        $afterSnapshot = $this->snapshotDirectory($this->tempDir);
        self::assertSame(
            $beforeSnapshot,
            $afterSnapshot,
            'GeneratePatchTool must not create/modify/delete any files in the controlled temp directory.',
        );

        $payload = $result->content[0]['data'] ?? null;
        self::assertIsArray($payload);
        foreach ($payload['patches'] as $patch) {
            $advertisedPath = $patch['file_path'] ?? '';
            self::assertNotSame('', $advertisedPath);
            self::assertFileDoesNotExist(
                $advertisedPath,
                sprintf('SC-005 violation: advertised patch path "%s" exists on disk.', $advertisedPath),
            );
        }
    }

    #[Test]
    public function envelopeShapeIsStable(): void // NFR-003
    {
        $tool = $this->makeTool();
        $result = $tool->execute(
            $this->validArguments(),
            $this->accountWithPermission('bimaaji.mutate'),
        );

        $payload = $result->content[0]['data'] ?? null;
        self::assertIsArray($payload);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded, 'PatchSet envelope must JSON round-trip cleanly.');
    }

    /**
     * Re-validation design choice.
     *
     * The WP03 spec contemplated accepting a pre-validated `MutationResult`
     * via tool arguments. JSON-deserialized arguments can't carry a
     * MutationResult object, and trusting client-supplied "validated"
     * state would defeat the validator's purpose. The tool therefore
     * re-runs validation internally — verified here by confirming that a
     * request which would fail validation produces an error envelope from
     * the tool, with no patches generated.
     */
    #[Test]
    public function chainsFromPreValidatedResult(): void
    {
        // Graph without 'Unknown' entity type — validation will reject.
        $tool = $this->makeTool();
        $args = $this->validArguments();
        $args['entity_type'] = 'Unknown';

        $result = $tool->execute(
            $args,
            $this->accountWithPermission('bimaaji.mutate'),
        );

        self::assertTrue($result->isError, 'Tool must re-validate; an unknown entity must surface as a tool error.');
        self::assertSame('validation failed', $result->summary);
    }

    #[Test]
    public function rejectsAccountWithoutCapability(): void // FR-006 / FR-010
    {
        $tool = $this->makeTool();
        $result = $tool->execute(
            $this->validArguments(),
            $this->accountWithPermission('bimaaji.read'),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function rejectsMissingArguments(): void
    {
        $tool = $this->makeTool();
        $result = $tool->execute([], $this->accountWithPermission('bimaaji.mutate'));

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    private function makeTool(): GeneratePatchTool
    {
        $graph = new ApplicationGraph(version: '1.0', sections: [
            new GraphSection(key: 'entities', version: '1.0', data: [
                'user' => ['label' => 'User', 'class' => 'App\\Entity\\User'],
            ]),
        ]);

        return new GeneratePatchTool(
            patchGenerator: new PatchGenerator(),
            validator: new MutationValidator($graph),
        );
    }

    /** @return array<string, mixed> */
    private function validArguments(): array
    {
        return [
            'operation' => 'add_field',
            'entity_type' => 'user',
            'field' => 'nickname',
            'parameters' => ['type' => 'string'],
        ];
    }

    /**
     * @return array<string, string> Filename → sha256(content). Stable, sorted.
     */
    private function snapshotDirectory(string $dir): array
    {
        $snapshot = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_file($full)) {
                $snapshot[$entry] = hash_file('sha256', $full) ?: '';
                continue;
            }
            $snapshot[$entry] = 'DIR';
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function accountWithPermission(string $permission): AccountInterface
    {
        return new class ($permission) implements AccountInterface {
            public function __construct(private readonly string $grantedPermission) {}

            public function id(): int
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === $this->grantedPermission;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
