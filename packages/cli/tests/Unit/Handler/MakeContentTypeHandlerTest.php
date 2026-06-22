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
}
