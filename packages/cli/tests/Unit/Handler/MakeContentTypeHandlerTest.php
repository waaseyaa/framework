<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeContentTypeHandler;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(MakeContentTypeHandler::class)]
final class MakeContentTypeHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_mct_' . uniqid();
        mkdir($this->root, 0o755, true);
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'app/app', 'autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    private function command(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'make:content-type',
            description: 'Scaffold a content type',
            arguments: [new HandlerArgument(name: 'name', mode: HandlerArgumentMode::Required, description: 'name')],
            options: [
                new HandlerOption(name: 'fields', mode: HandlerOptionMode::Required, description: 'fields', default: 'title:string,body:text'),
                new HandlerOption(name: 'force', mode: HandlerOptionMode::None, description: 'force'),
            ],
            handler: \Closure::fromCallable([new MakeContentTypeHandler(projectRoot: $this->root), 'execute']),
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not used');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function runMake(array $argv): CliTester
    {
        $tester = CliTester::for($this->command(), $this->emptyContainer());
        $tester->executeMap($argv);

        return $tester;
    }

    #[Test]
    public function generates_a_usable_content_type(): void
    {
        $tester = $this->runMake([
            'name' => 'story',
            '--fields' => 'title:string,body:text,source_url:string,author:entity_reference:user',
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

        $entity = (string) file_get_contents($this->root . '/src/Entity/Story.php');
        self::assertStringContainsString('namespace App\\Entity;', $entity);
        self::assertStringContainsString("#[ContentEntityType(id: 'story', label: 'Story')]", $entity);
        // Label key uses the first string field.
        self::assertStringContainsString("#[ContentEntityKeys(label: 'title')]", $entity);
        self::assertStringContainsString('final class Story extends ContentEntityBase', $entity);
        // Published status field is added automatically.
        self::assertStringContainsString("#[Field(type: 'boolean', label: 'Published', default: true)]", $entity);
        self::assertStringContainsString('public bool $status = true;', $entity);
        // Requested fields with correct PHP types.
        self::assertStringContainsString('public string $title', $entity);
        self::assertStringContainsString("type: 'text'", $entity);
        self::assertStringContainsString('public ?string $body', $entity);
        // entity_reference carries target metadata — no constructor spelunking.
        self::assertStringContainsString("settings: ['target_entity_type_id' => 'user']", $entity);
        self::assertStringContainsString('public ?int $author', $entity);
    }

    #[Test]
    public function generates_and_registers_a_provider(): void
    {
        $this->runMake(['name' => 'story', '--fields' => 'title:string']);

        $provider = (string) file_get_contents($this->root . '/src/Provider/StoryServiceProvider.php');
        self::assertStringContainsString('final class StoryServiceProvider extends ServiceProvider', $provider);
        self::assertStringContainsString("EntityType::fromClass(Story::class, group: 'content')", $provider);

        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains('App\\Provider\\StoryServiceProvider', $composer['extra']['waaseyaa']['providers']);
    }

    #[Test]
    public function provider_registration_is_idempotent(): void
    {
        $this->runMake(['name' => 'story', '--fields' => 'title:string']);
        $second = $this->runMake(['name' => 'story', '--fields' => 'title:string', '--force' => true]);

        self::assertSame(0, $second->getExitCode(), $second->getStderr());
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $providers = $composer['extra']['waaseyaa']['providers'];
        self::assertSame(['App\\Provider\\StoryServiceProvider'], array_values($providers), 'provider listed exactly once');
    }

    #[Test]
    public function refuses_to_overwrite_without_force(): void
    {
        $this->runMake(['name' => 'story', '--fields' => 'title:string']);
        $again = $this->runMake(['name' => 'story', '--fields' => 'title:string']);

        self::assertSame(1, $again->getExitCode());
        self::assertStringContainsString('already exists', $again->getStderr());
    }

    #[Test]
    public function rejects_entity_reference_without_target(): void
    {
        $tester = $this->runMake(['name' => 'story', '--fields' => 'author:entity_reference']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('needs a target', $tester->getStderr());
    }

    #[Test]
    public function rejects_unknown_field_type(): void
    {
        $tester = $this->runMake(['name' => 'story', '--fields' => 'title:wat']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown field type', $tester->getStderr());
    }

    #[Test]
    public function rejects_a_quote_breakout_payload_in_name_and_writes_no_file(): void
    {
        $tester = $this->runMake([
            'name' => "foo', system('touch pwned'); //",
            '--fields' => 'title:string',
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Invalid name', $tester->getStderr());
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
        self::assertDirectoryDoesNotExist($this->root . '/src/Provider');
    }

    #[Test]
    public function rejects_a_path_traversal_name_and_writes_no_file(): void
    {
        $tester = $this->runMake([
            'name' => '../evil',
            '--fields' => 'title:string',
        ]);

        self::assertSame(1, $tester->getExitCode());
        // Would otherwise resolve to $root/src/evil.php (one ".." escaping
        // the intended src/Entity/ directory) — stays inside the test's own
        // tmp sandbox so tearDown() still cleans it up if this ever regresses.
        self::assertFileDoesNotExist($this->root . '/src/evil.php');
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
    }

    #[Test]
    public function rejects_a_newline_injected_name(): void
    {
        $tester = $this->runMake([
            'name' => "story\n} class Evil {",
            '--fields' => 'title:string',
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
    }

    #[Test]
    public function rejects_a_trailing_newline_name_and_writes_no_file(): void
    {
        // A valid identifier prefix followed by a TRAILING newline: PHP's `$`
        // (without the `D` modifier) matches before the final `\n`, so an
        // allowlist regex lacking `D` would ACCEPT this and generate
        // `final class Story\nServiceProvider` — a php -l parse error that also
        // corrupts the composer-registered provider FQCN. The `D` modifier
        // closes this bypass.
        $tester = $this->runMake([
            'name' => "Story\n",
            '--fields' => 'title:string',
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Invalid name', $tester->getStderr());
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
        self::assertDirectoryDoesNotExist($this->root . '/src/Provider');
    }

    #[Test]
    public function accepts_an_indigenous_orthography_name_and_generates_valid_php(): void
    {
        // Charter regression-guard: an Indigenous-language content-type name
        // with diacritics AND one with Canadian Aboriginal Syllabics are valid
        // PHP identifier byte sequences (PHP allows identifier bytes >= 0x80)
        // that worked pre-fix. The Unicode-aware allowlist MUST still accept
        // them, preserve the non-Latin characters verbatim in BOTH the class
        // name and the human-facing label literal, and generate valid PHP.
        // Diacritics: "Anishinaabemowin" with an accented "í".
        $this->assertOrthographyPreserved("An\u{00ED}shinaabemowin");
        // Canadian Aboriginal Syllabics: "ᐊᓂᔑᓈᐯ" (Anishinaabe).
        $this->assertOrthographyPreserved("\u{140A}\u{14C2}\u{1450}\u{1448}\u{1426}");
    }

    private function assertOrthographyPreserved(string $name): void
    {
        // Fresh sandbox per name so each generates into a clean tree.
        $root = sys_get_temp_dir() . '/waaseyaa_orth_' . uniqid('', true);
        mkdir($root, 0o755, true);
        file_put_contents(
            $root . '/composer.json',
            (string) json_encode(['name' => 'app/app', 'autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_PRETTY_PRINT),
        );

        try {
            $command = new HandlerCommand(
                name: 'make:content-type',
                description: 'Scaffold a content type',
                arguments: [new HandlerArgument(name: 'name', mode: HandlerArgumentMode::Required, description: 'name')],
                options: [
                    new HandlerOption(name: 'fields', mode: HandlerOptionMode::Required, description: 'fields', default: 'title:string'),
                    new HandlerOption(name: 'force', mode: HandlerOptionMode::None, description: 'force'),
                ],
                handler: \Closure::fromCallable([new MakeContentTypeHandler(projectRoot: $root), 'execute']),
            );
            $tester = CliTester::for($command, $this->emptyContainer());
            $tester->executeMap(['name' => $name, '--fields' => 'title:string']);

            self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

            $expectedClass = str_replace('_', '', ucwords($name, '_'));
            $entityPath = $root . '/src/Entity/' . $expectedClass . '.php';
            self::assertFileExists($entityPath);

            $entity = (string) file_get_contents($entityPath);
            // Non-Latin characters preserved verbatim in the class name...
            self::assertStringContainsString('final class ' . $expectedClass . ' extends ContentEntityBase', $entity);
            // ...and in the human-facing label literal (derived from the name).
            self::assertStringContainsString($name, $entity);
            // Generated file is syntactically valid PHP.
            exec('php -l ' . escapeshellarg($entityPath) . ' 2>&1', $lintOutput, $exitCode);
            self::assertSame(0, $exitCode, sprintf('%s failed php -l: %s', $entityPath, implode("\n", $lintOutput)));
        } finally {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($root);
        }
    }

    #[Test]
    public function rejects_a_quote_breakout_payload_in_entity_reference_target(): void
    {
        $tester = $this->runMake([
            'name' => 'story',
            // Comma-free payload — the --fields spec itself is comma-delimited,
            // so a comma would just start a second (differently-broken) field
            // instead of exercising the target-specific breakout.
            '--fields' => "author:entity_reference:user'); system('touch pwned'); //",
        ]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Invalid entity_reference target', $tester->getStderr());
        self::assertDirectoryDoesNotExist($this->root . '/src/Entity');
    }

    #[Test]
    public function generated_entity_and_provider_are_syntactically_valid_php(): void
    {
        $tester = $this->runMake([
            'name' => 'story',
            '--fields' => 'title:string,body:text,source_url:string,author:entity_reference:user',
        ]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

        foreach ([$this->root . '/src/Entity/Story.php', $this->root . '/src/Provider/StoryServiceProvider.php'] as $file) {
            self::assertFileExists($file);
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $exitCode);
            self::assertSame(0, $exitCode, sprintf('%s failed php -l: %s', $file, implode("\n", $lintOutput)));
        }
    }
}
