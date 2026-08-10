<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\CommunityTranslationPeerRepairHandler;
use Waaseyaa\CLI\Provider\HealthSchemaServiceProvider;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tenancy\CommunityTranslationPeerRepairer;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestTranslatableEntity;

#[CoversClass(CommunityTranslationPeerRepairHandler::class)]
#[CoversClass(HealthSchemaServiceProvider::class)]
final class CommunityTranslationPeerRepairHandlerTest extends TestCase
{
    #[Test]
    public function commandIsRegisteredWithDryRunAndJsonOptions(): void
    {
        $command = null;
        foreach ((new HealthSchemaServiceProvider())->consoleCommands() as $candidate) {
            if ($candidate->name === 'tenancy:repair-translation-peers') {
                $command = $candidate;
                break;
            }
        }

        self::assertNotNull($command);
        $options = [];
        foreach ($command->handlerOptions() as $option) {
            $options[] = $option->name;
        }
        self::assertContains('dry-run', $options);
        self::assertContains('json', $options);
    }

    #[Test]
    public function dryRunEmitsMachineReadableCountsWithoutChangingRows(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = new EntityType(
            id: 'repair_cli_translation',
            label: 'Repair CLI translation',
            class: TestTranslatableEntity::class,
            keys: [
                'id' => 'id',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        new SqlSchemaHandler($type, $database)->ensureTable();
        $database->insert('repair_cli_translation')
            ->fields(['id', 'langcode', 'default_langcode', 'community_id'])
            ->values(['1', 'en', 'en', 'community-a'])
            ->execute();
        $database->insert('repair_cli_translation')
            ->fields(['id', 'langcode', 'default_langcode', 'community_id'])
            ->values(['1', 'oj', 'en', ''])
            ->execute();
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($type);
        $handler = new CommunityTranslationPeerRepairHandler(
            $manager,
            new CommunityTranslationPeerRepairer($database),
        );
        $definition = new InputDefinition([
            new InputArgument('entity_type', InputArgument::REQUIRED),
            new InputOption('dry-run', null, InputOption::VALUE_NONE),
            new InputOption('json', null, InputOption::VALUE_NONE),
        ]);
        $output = new BufferedOutput();
        $input = new ArrayInput([
            'entity_type' => 'repair_cli_translation',
            '--dry-run' => true,
            '--json' => true,
        ], $definition);

        self::assertSame(0, $handler->execute(new SymfonyCommandIO($input, $output)));
        self::assertSame([
            'examined' => 1,
            'eligible' => 1,
            'repaired' => 0,
            'skipped' => 0,
            'dry_run' => true,
        ], json_decode(trim($output->fetch()), true, flags: \JSON_THROW_ON_ERROR));
        $peer = iterator_to_array($database->query(
            "SELECT community_id FROM repair_cli_translation WHERE id = '1' AND langcode = 'oj'",
        ), false);
        self::assertSame('', $peer[0]['community_id']);

        $textOutput = new BufferedOutput();
        $textInput = new ArrayInput([
            'entity_type' => 'repair_cli_translation',
            '--dry-run' => true,
        ], $definition);
        self::assertSame(0, $handler->execute(new SymfonyCommandIO($textInput, $textOutput)));
        self::assertStringContainsString('Dry-run translation-peer tenancy repair', $textOutput->fetch());

        $unknownOutput = new BufferedOutput();
        $unknownInput = new ArrayInput(['entity_type' => 'missing_type'], $definition);
        self::assertSame(1, $handler->execute(new SymfonyCommandIO($unknownInput, $unknownOutput)));
        self::assertStringContainsString('Unknown entity type', $unknownOutput->fetch());

        $invalidType = new EntityType(
            id: 'not_scoped',
            label: 'Not scoped',
            class: TestTranslatableEntity::class,
            keys: [
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
        );
        $manager->registerEntityType($invalidType);
        $invalidOutput = new BufferedOutput();
        $invalidInput = new ArrayInput(['entity_type' => 'not_scoped'], $definition);
        self::assertSame(1, $handler->execute(new SymfonyCommandIO($invalidInput, $invalidOutput)));
        self::assertStringContainsString('not community-scoped', $invalidOutput->fetch());
    }
}
