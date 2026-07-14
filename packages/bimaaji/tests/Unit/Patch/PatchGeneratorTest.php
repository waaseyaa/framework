<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Patch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Mutation\MutationRequest;
use Waaseyaa\Bimaaji\Mutation\MutationResult;
use Waaseyaa\Bimaaji\Patch\PatchGenerator;
use Waaseyaa\Bimaaji\Patch\PatchSet;

#[CoversClass(PatchGenerator::class)]
final class PatchGeneratorTest extends TestCase
{
    private PatchGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new PatchGenerator();
    }

    #[Test]
    public function it_throws_on_failed_mutation_result(): void
    {
        $request = new MutationRequest(
            operation: 'add_field',
            entityType: 'node',
            field: 'subtitle',
        );
        $result = MutationResult::failure($request, ['something went wrong']);

        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generate($result);
    }

    #[Test]
    public function it_returns_empty_patch_set_for_unknown_operation(): void
    {
        $request = new MutationRequest(
            operation: 'unknown_op',
            entityType: 'node',
        );
        $result = MutationResult::success($request);

        $patchSet = $this->generator->generate($result);

        self::assertInstanceOf(PatchSet::class, $patchSet);
        self::assertCount(0, $patchSet->patches);
    }

    #[Test]
    public function it_generates_patch_for_add_field_operation(): void
    {
        $request = new MutationRequest(
            operation: 'add_field',
            entityType: 'article',
            field: 'subtitle',
            parameters: [
                'type' => 'string',
                'label' => 'Subtitle',
            ],
        );
        $result = MutationResult::success($request);

        $patchSet = $this->generator->generate($result);

        self::assertCount(1, $patchSet->patches);

        $patch = $patchSet->patches[0];
        self::assertStringContainsString('subtitle', $patch->content);
        self::assertStringContainsString('string', $patch->content);
        self::assertFalse($patch->unsafe);
        self::assertSame(hash('sha256', $patch->content), $patch->contentHash);
        // Verify the generated content is valid PHP
        self::assertStringStartsWith('<?php', $patch->content);
    }

    #[Test]
    public function add_field_patch_contains_valid_php(): void
    {
        $request = new MutationRequest(
            operation: 'add_field',
            entityType: 'article',
            field: 'body',
            parameters: [
                'type' => 'text',
                'label' => 'Body',
                'required' => true,
            ],
        );
        $result = MutationResult::success($request);

        $patchSet = $this->generator->generate($result);
        $content = $patchSet->patches[0]->content;

        // Round-trip through php-parser to verify validity
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($content);

        self::assertNotNull($stmts, 'Generated PHP should parse without errors');
        self::assertNotEmpty($stmts);
    }

    #[Test]
    public function add_field_patch_file_path_contains_entity_type(): void
    {
        $request = new MutationRequest(
            operation: 'add_field',
            entityType: 'article',
            field: 'subtitle',
            parameters: [
                'type' => 'string',
                'label' => 'Subtitle',
            ],
        );
        $result = MutationResult::success($request);

        $patchSet = $this->generator->generate($result);

        self::assertStringContainsString('article', $patchSet->patches[0]->filePath);
    }

    #[Test]
    public function add_field_patch_computes_unsafe_from_identifier_allowlist(): void
    {
        $request = new MutationRequest(
            operation: 'add_field',
            entityType: '../article',
            field: '../../shell',
            parameters: ['type' => 'string'],
        );

        $patch = $this->generator->generate(MutationResult::success($request))->patches[0];

        self::assertTrue($patch->unsafe);
    }
}
