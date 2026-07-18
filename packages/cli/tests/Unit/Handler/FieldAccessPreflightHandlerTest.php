<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Provider\HealthSchemaServiceProvider;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinitionRegistry;

final class FieldAccessPreflightHandlerTest extends TestCase
{
    public function test_exact_preflight_command_is_registered_with_read_only_default(): void
    {
        $command = null;
        foreach ((new HealthSchemaServiceProvider())->consoleCommands() as $candidate) {
            if ($candidate->name === 'field-access:preflight') {
                $command = $candidate;
                break;
            }
        }

        self::assertNotNull($command);
        self::assertSame('field-access:preflight', $command->name);
        $options = [];
        foreach ($command->handlerOptions() as $option) {
            $options[$option->name] = $option;
        }
        self::assertSame('json', $options['format']->default);
        self::assertArrayHasKey('write-artifact', $options);
    }

    public function test_execution_is_read_only_by_default_and_explicit_write_atomically_replaces_artifact(): void
    {
        $root = sys_get_temp_dir().'/waaseyaa-field-preflight-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root.'/.waaseyaa', 0775, true));
        file_put_contents($root.'/VERSION', "0.1.0-test\n");
        file_put_contents($root.'/composer.lock', '{}');
        $target = $root.'/.waaseyaa/field-access-preflight.json';
        file_put_contents($target, 'previous');

        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE audit_sentinel (id INTEGER PRIMARY KEY, value TEXT)');
        $database->query("INSERT INTO audit_sentinel (id, value) VALUES (1, 'unchanged')");
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $handler = new FieldAccessPreflightHandler(
            new DatabaseFieldAccessInventoryScanner($database, $manager),
            $manager,
            projectRoot: $root,
        );
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED, '', 'json'),
            new InputOption('write-artifact', null, InputOption::VALUE_NONE),
        ]);

        $readOnlyOutput = new BufferedOutput();
        self::assertSame(0, $handler->execute(new SymfonyCommandIO(new ArrayInput([], $definition), $readOnlyOutput)));
        self::assertSame('previous', file_get_contents($target));
        self::assertSame('unchanged', iterator_to_array($database->query('SELECT value FROM audit_sentinel WHERE id = 1'), false)[0]['value']);

        $writeOutput = new BufferedOutput();
        self::assertSame(0, $handler->execute(new SymfonyCommandIO(new ArrayInput(['--write-artifact' => true], $definition), $writeOutput)));
        self::assertJson((string) file_get_contents($target));
        self::assertSame([], glob($root.'/.waaseyaa/.field-access-preflight.*') ?: []);
        self::assertSame('unchanged', iterator_to_array($database->query('SELECT value FROM audit_sentinel WHERE id = 1'), false)[0]['value']);

        unlink($target);
        rmdir($root.'/.waaseyaa');
        unlink($root.'/VERSION');
        unlink($root.'/composer.lock');
        rmdir($root);
    }
}
