<?php

declare(strict_types=1);

use EngagementConsumer\SyntheticPost;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

require __DIR__ . '/vendor/autoload.php';

$mode = $argv[1] ?? 'seed';
if (!in_array($mode, ['boot', 'seed', 'seed-legacy'], true)) {
    fwrite(STDERR, "Usage: php bootstrap.php [boot|seed|seed-legacy]\n");
    exit(2);
}

try {
    $kernel = new HttpKernel(__DIR__);
    $kernel->bootForCli();
    $types = $kernel->getEntityTypeManager();
    if ($mode === 'boot') {
        $layout = getenv('ENGAGEMENT_LAYOUT') ?: 'sql-blob';
        if (!in_array($layout, ['sql-blob', 'sql-column'], true)) {
            throw new RuntimeException('ENGAGEMENT_LAYOUT must be sql-blob or sql-column.');
        }
        foreach (['comment', 'reaction'] as $typeId) {
            if (!$types->hasDefinition($typeId)) {
                throw new RuntimeException("Installed engagement package did not register {$typeId}.");
            }
            $backend = $types->getDefinition($typeId)->getPrimaryStorageBackend();
            if ($backend !== $layout) {
                throw new RuntimeException("{$typeId} storage backend is " . var_export($backend, true) . ", expected {$layout}.");
            }
        }

        $form = getenv('ENGAGEMENT_FORM');
        if (!in_array($form, ['dev', 'no-dev'], true)) {
            throw new RuntimeException('ENGAGEMENT_FORM must be dev or no-dev.');
        }
        $phpunitPresent = class_exists(\PHPUnit\Framework\TestCase::class);
        if ($phpunitPresent !== ($form === 'dev')) {
            throw new RuntimeException('Installed PHPUnit presence does not match the selected Composer profile.');
        }
        $engagementProvider = new ReflectionClass(\Waaseyaa\Engagement\EngagementServiceProvider::class);
        $providerFile = realpath((string) $engagementProvider->getFileName());
        $installedPackage = realpath(__DIR__ . '/vendor/waaseyaa/engagement');
        if ($providerFile === false || $installedPackage === false
            || !str_starts_with($providerFile, $installedPackage . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Engagement runtime resolved outside the installed consumer package bytes.');
        }
        echo "engagement consumer runtime boot OK\n";
        exit(0);
    }

    $userRepository = $types->getRepository('user');
    $users = [];
    foreach ([1 => ['publisher-alice', 'publisher-alice@example.test'], 2 => ['moderator-bob', 'moderator-bob@example.test'], 3 => ['featured-subject', 'featured-subject@example.test']] as $uid => [$name, $mail]) {
        $existing = $userRepository->find((string) $uid);
        if (!$existing instanceof User) {
            $user = User::make([
                'name' => $name, 'mail' => $mail, 'status' => 1,
                'roles' => ['authenticated'], 'permissions' => ['access content'],
                'created' => time(),
            ]);
            $userRepository->save($user);
            if ((int) $user->id() !== $uid) {
                throw new RuntimeException("Fixture identity ordering did not produce uid {$uid}.");
            }
            $existing = $user;
        }
        $users[match ($uid) {
            1 => 'alice', 2 => 'bob', default => 'featured'
        }] = (int) $existing->id();
    }

    $postRepository = $types->getRepository('synthetic_post');
    $roots = [];
    foreach ([
        ['publisher_id' => 1, 'featured_subject_id' => 3, 'title' => 'Synthetic published post one'],
        ['publisher_id' => 2, 'featured_subject_id' => 3, 'title' => 'Synthetic published post two'],
    ] as $index => $values) {
        $post = $postRepository->find((string) ($index + 1));
        if (!$post instanceof SyntheticPost) {
            $post = new SyntheticPost($values + ['status' => true]);
            $postRepository->save($post);
        }
        $roots[] = ['type' => 'synthetic_post', 'id' => (int) $post->id(), 'uuid' => $post->uuid()];
    }

    $database = $kernel->buildHandlerContainer()->get(\Waaseyaa\Database\DatabaseInterface::class);
    if (!$database instanceof \Waaseyaa\Database\DBALDatabase) {
        throw new RuntimeException('Fixture requires canonical DBALDatabase.');
    }
    $connection = $database->getConnection();
    $connection->executeStatement('INSERT OR IGNORE INTO engagement_fixture_policy (id, generation, root_visible, alice_blocked, alice_suspended, bob_moderator) VALUES (1, 1, 1, 0, 0, 0)');

    $state = ['users' => $users, 'roots' => $roots];
    file_put_contents(__DIR__ . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "engagement consumer fixture seeded\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'engagement consumer bootstrap failed: ' . $failure::class . ': ' . $failure->getMessage() . "\n");
    exit(1);
}
