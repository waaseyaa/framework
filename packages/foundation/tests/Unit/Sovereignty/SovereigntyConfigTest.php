<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Sovereignty;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfig;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyDefaults;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

#[CoversClass(SovereigntyConfig::class)]
#[CoversClass(SovereigntyProfile::class)]
#[CoversClass(SovereigntyDefaults::class)]
final class SovereigntyConfigTest extends TestCase
{
    #[Test]
    public function profileEnumHasThreeCases(): void
    {
        $cases = SovereigntyProfile::cases();

        self::assertCount(3, $cases);
        self::assertSame('local', SovereigntyProfile::Local->value);
        self::assertSame('self_hosted', SovereigntyProfile::SelfHosted->value);
        self::assertSame('northops', SovereigntyProfile::NorthOps->value);
    }

    #[Test]
    public function profileCanBeCreatedFromString(): void
    {
        self::assertSame(SovereigntyProfile::Local, SovereigntyProfile::from('local'));
        self::assertSame(SovereigntyProfile::SelfHosted, SovereigntyProfile::from('self_hosted'));
        self::assertSame(SovereigntyProfile::NorthOps, SovereigntyProfile::from('northops'));
    }

    #[Test]
    public function defaultsReturnsCompleteSettingsForAllProfiles(): void
    {
        $expectedKeys = ['storage', 'embeddings', 'llm_provider', 'transcriber', 'vector_store', 'queue_backend'];

        foreach (SovereigntyProfile::cases() as $profile) {
            $defaults = SovereigntyDefaults::for($profile);

            foreach ($expectedKeys as $key) {
                self::assertArrayHasKey($key, $defaults, sprintf(
                    'Profile "%s" is missing default for key "%s"',
                    $profile->value,
                    $key,
                ));
                self::assertIsString($defaults[$key], sprintf(
                    'Profile "%s" key "%s" must be a string',
                    $profile->value,
                    $key,
                ));
            }
        }
    }

    #[Test]
    public function localProfileHasExpectedDefaults(): void
    {
        $defaults = SovereigntyDefaults::for(SovereigntyProfile::Local);

        self::assertSame('filesystem', $defaults['storage']);
        self::assertSame('sqlite', $defaults['embeddings']);
        self::assertSame('ollama', $defaults['llm_provider']);
        self::assertSame('whisper_ollama', $defaults['transcriber']);
        self::assertSame('sqlite', $defaults['vector_store']);
        self::assertSame('sync', $defaults['queue_backend']);
    }

    #[Test]
    public function northopsProfileHasExpectedDefaults(): void
    {
        $defaults = SovereigntyDefaults::for(SovereigntyProfile::NorthOps);

        self::assertSame('s3', $defaults['storage']);
        self::assertSame('pgvector', $defaults['embeddings']);
        self::assertSame('api', $defaults['llm_provider']);
        self::assertSame('api', $defaults['transcriber']);
        self::assertSame('pgvector', $defaults['vector_store']);
        self::assertSame('redis', $defaults['queue_backend']);
    }

    #[Test]
    public function configImplementsInterface(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::Local, []);

        self::assertInstanceOf(SovereigntyConfigInterface::class, $config);
    }

    #[Test]
    public function getReturnsProfileDefaultWhenNoOverride(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::Local, []);

        self::assertSame('ollama', $config->get('llm_provider'));
        self::assertSame('sync', $config->get('queue_backend'));
    }

    #[Test]
    public function getReturnsOverrideWhenSet(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::Local, [
            'llm_provider' => 'api',
        ]);

        self::assertSame('api', $config->get('llm_provider'));
        // Non-overridden keys still return profile default.
        self::assertSame('sync', $config->get('queue_backend'));
    }

    #[Test]
    public function getReturnsNullForUnknownKey(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::Local, []);

        self::assertNull($config->get('nonexistent_key'));
    }

    #[Test]
    public function getProfileReturnsActiveProfile(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::SelfHosted, []);

        self::assertSame(SovereigntyProfile::SelfHosted, $config->getProfile());
    }

    #[Test]
    public function allReturnsmergedDefaultsAndOverrides(): void
    {
        $config = new SovereigntyConfig(SovereigntyProfile::Local, [
            'llm_provider' => 'api',
            'vector_store' => 'pgvector',
        ]);

        $all = $config->all();

        self::assertSame('api', $all['llm_provider']);
        self::assertSame('pgvector', $all['vector_store']);
        self::assertSame('filesystem', $all['storage']);
        self::assertSame('sync', $all['queue_backend']);
    }

    #[Test]
    public function createFromConfigArrayResolvesProfile(): void
    {
        $appConfig = [
            'sovereignty_profile' => 'local',
            'llm_provider' => 'api',
        ];

        $config = SovereigntyConfig::fromArray($appConfig);

        self::assertSame(SovereigntyProfile::Local, $config->getProfile());
        self::assertSame('api', $config->get('llm_provider'));
    }

    #[Test]
    public function createFromConfigArrayDefaultsToLocal(): void
    {
        $config = SovereigntyConfig::fromArray([]);

        self::assertSame(SovereigntyProfile::Local, $config->getProfile());
    }

    #[Test]
    public function createFromConfigArrayFallsBackToLocalOnInvalidProfile(): void
    {
        $config = SovereigntyConfig::fromArray(['sovereignty_profile' => 'bogus']);

        self::assertSame(SovereigntyProfile::Local, $config->getProfile());
    }
}
