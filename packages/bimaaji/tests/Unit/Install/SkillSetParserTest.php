<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillSetParser;

#[CoversClass(SkillSetParser::class)]
final class SkillSetParserTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_skill_set_parser_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function returnsEmptyListWhenDirectoryDoesNotExist(): void
    {
        $parser = new SkillSetParser($this->tempDir . '/missing');

        self::assertSame([], $parser->parse());
    }

    #[Test]
    public function returnsEmptyListForEmptyDirectory(): void
    {
        $parser = new SkillSetParser($this->tempDir);

        self::assertSame([], $parser->parse());
    }

    #[Test]
    public function parsesYamlFrontmatterAndStripsItFromBody(): void
    {
        $this->writeSkill('alpha', <<<MD
            ---
            name: Alpha skill
            description: First skill body
            ---

            # Alpha

            Use this skill when an alpha event occurs.
            MD);

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertCount(1, $skills);
        self::assertSame('alpha', $skills[0]->id);
        self::assertSame('Alpha skill', $skills[0]->name);
        self::assertSame('First skill body', $skills[0]->description);
        self::assertStringStartsWith('# Alpha', $skills[0]->body);
        self::assertStringContainsString('alpha event', $skills[0]->body);
        // Frontmatter delimiter must not survive in the body.
        self::assertStringNotContainsString('---', substr($skills[0]->body, 0, 4));
    }

    #[Test]
    public function sortsSkillsByIdForDeterministicOrdering(): void
    {
        $this->writeSkill('gamma', "---\nname: G\n---\nG body");
        $this->writeSkill('alpha', "---\nname: A\n---\nA body");
        $this->writeSkill('beta', "---\nname: B\n---\nB body");

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertSame(['alpha', 'beta', 'gamma'], array_map(static fn(ParsedSkill $s): string => $s->id, $skills));
    }

    #[Test]
    public function ignoresDirectoriesWithoutSkillMd(): void
    {
        mkdir($this->tempDir . '/empty-skill', 0o755);
        $this->writeSkill('real-skill', "---\nname: Real\n---\nbody");

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertCount(1, $skills);
        self::assertSame('real-skill', $skills[0]->id);
    }

    #[Test]
    public function fallsBackToIdWhenFrontmatterIsMissing(): void
    {
        $this->writeSkill('no-frontmatter', "# Just a body\n\nWith no frontmatter delimiter.");

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertCount(1, $skills);
        self::assertSame('no-frontmatter', $skills[0]->id);
        self::assertSame('no-frontmatter', $skills[0]->name);
        self::assertSame('', $skills[0]->description);
        self::assertStringStartsWith('# Just a body', $skills[0]->body);
    }

    #[Test]
    public function exposesFrontmatterScalars(): void
    {
        $this->writeSkill('typed', "---\nname: Typed\ndescription: Mixed types\nthreshold: 42\nactive: true\nnote: ~\n---\nbody");

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertCount(1, $skills);
        $frontmatter = $skills[0]->frontmatter;
        self::assertSame('Typed', $frontmatter['name']);
        self::assertSame(42, $frontmatter['threshold']);
        self::assertTrue($frontmatter['active']);
        self::assertNull($frontmatter['note']);
    }

    #[Test]
    public function handlesValuesContainingColons(): void
    {
        // `waaseyaa:infrastructure`-style values must survive — the parser
        // splits on the FIRST colon only.
        $this->writeSkill('infra', "---\nname: waaseyaa:infrastructure\ndescription: spans: colons fine\n---\nbody");

        $parser = new SkillSetParser($this->tempDir);
        $skills = $parser->parse();

        self::assertSame('waaseyaa:infrastructure', $skills[0]->name);
        self::assertSame('spans: colons fine', $skills[0]->description);
    }

    private function writeSkill(string $id, string $contents): void
    {
        $dir = $this->tempDir . DIRECTORY_SEPARATOR . $id;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/SKILL.md', $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
