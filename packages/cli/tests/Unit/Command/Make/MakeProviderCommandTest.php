<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\MakeProviderHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderB;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeProviderHandler::class)]
final class MakeProviderCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_service_provider(): void
    {
        $tester = $this->createTester();
        $tester->execute(['SitemapServiceProvider']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('class SitemapServiceProvider extends ServiceProvider', $output);
        $this->assertStringContainsString('public function register(): void', $output);
        $this->assertStringContainsString('public function boot(): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['sitemap_service_provider']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class SitemapServiceProvider extends ServiceProvider', $output);
    }

    #[Test]
    public function it_appends_service_provider_suffix(): void
    {
        $tester = $this->createTester();
        $tester->execute(['Blog']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class BlogServiceProvider extends ServiceProvider', $output);
    }

    #[Test]
    public function it_generates_domain_provider_with_entity_boilerplate(): void
    {
        $tester = $this->createTester();
        $tester->execute(['Blog', '--domain']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class BlogServiceProvider extends ServiceProvider', $output);
        $this->assertStringContainsString('EntityType::fromClass(', $output);
        $this->assertStringContainsString('Blog::class', $output);
        $this->assertStringContainsString("group: 'blog'", $output);
        $this->assertStringNotContainsString('fieldDefinitions:', $output);
        $this->assertStringNotContainsString('new EntityType(', $output);
    }

    #[Test]
    public function domain_flag_converts_multi_word_to_snake_case(): void
    {
        $tester = $this->createTester();
        $tester->execute(['UserProfile', '--domain']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('class UserProfileServiceProvider extends ServiceProvider', $output);
        $this->assertStringContainsString("group: 'user_profile'", $output);
    }

    #[Test]
    public function it_rejects_a_quote_breakout_payload_in_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["foo', system('touch pwned'); //"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
    }

    #[Test]
    public function it_rejects_a_quote_breakout_payload_with_domain_flag(): void
    {
        $tester = $this->createTester();
        $tester->execute(["foo', system('touch pwned'); //", '--domain']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
    }

    #[Test]
    public function it_rejects_a_path_traversal_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['../evil']);

        $this->assertSame(1, $tester->getExitCode());
    }

    #[Test]
    public function it_rejects_a_trailing_newline_name(): void
    {
        // make:provider (without --domain) validates only the name — no
        // downstream machine-name gate — so a trailing `\n` that slips past a
        // non-`D`-anchored allowlist would exit 0 and emit
        // `final class Foo\nServiceProvider` (a php -l parse error). The `D`
        // modifier on IDENTIFIER_PATTERN rejects it.
        $tester = $this->createTester();
        $tester->execute(["Blog\n"]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Invalid name', $tester->getStderr());
        $this->assertStringNotContainsString('class Blog', $tester->getStdout());
    }

    #[Test]
    public function it_accepts_an_indigenous_orthography_name(): void
    {
        // Charter regression-guard: a name with Canadian Aboriginal Syllabics
        // is a valid PHP identifier byte sequence that must still generate a
        // valid provider class (Unicode-aware allowlist, orthography preserved).
        $name = "\u{140A}\u{14C2}\u{1450}"; // ᐊᓂᔑ
        $tester = $this->createTester();
        $tester->execute([$name]);

        $this->assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $this->assertStringContainsString('class ' . $name . 'ServiceProvider extends ServiceProvider', $tester->getStdout());
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderB();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:provider') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakeProviderHandler::class) {
                    return new MakeProviderHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeProviderHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
