<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Install\PackagedSkillResources;
use Waaseyaa\Bimaaji\Install\SkillSetParser;

/**
 * The canonical Agent Skills must ship as resources of this component
 * package, and the default resolution must start there (#2656).
 *
 * Before #2656 the skills lived at the monorepo root (`skills/waaseyaa/`),
 * a path that only ever existed in the framework checkout. `bimaaji:install`
 * therefore worked exclusively in the repository that did not need it and
 * exited 1 for every downstream consumer. This test is the repo-state half
 * of the fix; `tests/PackagedForm/check-bimaaji-skill-resources` is the
 * end-to-end half that proves it from a real packaged consumer.
 */
#[CoversNothing]
final class PackagedSkillResourcesTest extends TestCase
{
    #[Test]
    public function theSkillResourcesShipInsideThePackage(): void
    {
        $directory = PackagedSkillResources::directory();

        self::assertDirectoryExists($directory);
        self::assertSame(
            realpath(\dirname(__DIR__, 2) . '/resources/skills'),
            realpath($directory),
            'The packaged skill set must resolve to packages/bimaaji/resources/skills.',
        );
    }

    #[Test]
    public function everyShippedSkillParsesWithANameDescriptionAndBody(): void
    {
        $skills = new SkillSetParser(PackagedSkillResources::directory())->parse();

        self::assertNotEmpty($skills, 'waaseyaa/bimaaji must ship at least one Agent Skill.');

        foreach ($skills as $skill) {
            self::assertNotSame('', $skill->name, sprintf('Skill %s has no frontmatter name.', $skill->id));
            self::assertNotSame('', $skill->description, sprintf('Skill %s has no frontmatter description.', $skill->id));
            self::assertNotSame('', $skill->body, sprintf('Skill %s has an empty body.', $skill->id));
            self::assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $skill->id,
                'Skill directory names are kebab-case identifiers.',
            );
        }
    }

    #[Test]
    public function theDefaultResolutionIsThePackagedDirectoryNotAProjectRootGuess(): void
    {
        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext(
            projectRoot: sys_get_temp_dir() . '/waaseyaa-not-a-framework-checkout',
            config: [],
            manifestFormatters: [],
        );
        $provider->register();

        $parser = $provider->resolve(SkillSetParser::class);
        self::assertInstanceOf(SkillSetParser::class, $parser);
        self::assertSame(PackagedSkillResources::directory(), $parser->directory());
        // Resolution must not fall back to <projectRoot>/skills/waaseyaa.
        self::assertNotEmpty($parser->parse());
    }

    #[Test]
    public function aConfiguredDirectoryStillOverridesThePackagedDefault(): void
    {
        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext(
            projectRoot: sys_get_temp_dir(),
            config: ['bimaaji' => ['skills_directory' => '/somewhere/else']],
            manifestFormatters: [],
        );
        $provider->register();

        $parser = $provider->resolve(SkillSetParser::class);
        self::assertInstanceOf(SkillSetParser::class, $parser);
        self::assertSame('/somewhere/else', $parser->directory());
    }

    #[Test]
    public function theMonorepoOnlySkillsDirectoryIsGone(): void
    {
        self::assertDirectoryDoesNotExist(
            \dirname(__DIR__, 4) . '/skills',
            'skills/ at the repository root was the monorepo-only source #2656 removed; '
            . 'the canonical set lives in packages/bimaaji/resources/skills.',
        );
    }
}
