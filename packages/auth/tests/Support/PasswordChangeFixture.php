<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\Password\LegacyPasswordChange;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\Password\LegacyPasswordVerifierChain;
use Waaseyaa\Auth\Password\WordPressBcryptPasswordVerifier;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\User;

final class PasswordChangeFixture
{
    public function fixture(bool $portable = false): array
    {
        $db = DBALDatabase::createSqlite();
        $migration = require dirname(__DIR__, 2).'/migrations/2026_08_12_000001_auth_runtime_schema.php';
        $migration->up(new \Waaseyaa\Foundation\Migration\SchemaBuilder($db->getConnection()));
        $type = EntityType::fromClass(User::class);
        new SqlSchemaHandler($type, $db)->ensureTable();
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver($type, new SqlStorageDriver(new SingleConnectionResolver($db), 'uid'), new EventDispatcher(), database: $db);
        $manager = new EntityTypeManager(new EventDispatcher(), repositoryFactory: static fn() => $repo);
        $manager->registerEntityType($type);
        $fields = new UserInternalFieldReaderFixture();
        $config = AuthConfig::fromArray(['legacy_passwords' => ['require_change' => true]], 'test');
        $chain = new LegacyPasswordVerifierChain(new WordPressBcryptPasswordVerifier(), new \Waaseyaa\Auth\Password\PhpassPasswordVerifier());
        $tokens = new AuthTokenRepository($db, 'synthetic-test-secret');
        $change = new LegacyPasswordChange($config, $chain, $fields, $tokens, $db);
        $legacy = '$wp'.password_hash(base64_encode(hash_hmac('sha384', 'old-password', 'wp-sha384', true)), PASSWORD_BCRYPT, ['cost' => 4]);
        if ($portable) { $legacy = '$P$BaBcD1234Jsk4IZgCDT7qlmyKUYqBG0'; }
        $user = new User(['uid' => 1, 'uuid' => 'synthetic-user', 'name' => 'legacy', 'mail' => 'test@example.invalid', 'status' => true, 'pass' => '', 'legacy_pass' => $legacy]);
        $user->enforceIsNew();
        $repo->save($user);
        return [$repo, $manager, $fields, $config, $tokens, $change, $user, $chain, $db];
    }

}
