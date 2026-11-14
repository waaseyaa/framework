<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementTokens;
use Waaseyaa\Engagement\Rekey\EngagementEphemeralTokenRekeyAdapter;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

#[CoversClass(EngagementTokens::class)]
final class EngagementTokensTest extends TestCase
{
    public function test_submission_and_cursor_claims_expiry_tampering_and_installation_binding(): void
    {
        $now = 1_700_000_000;
        $tokens = new EngagementTokens($this->keyring(), 'install-a', static function () use (&$now): int { return $now; });
        $key = $tokens->issueSubmission('actor-1', 'article', 'root-uuid', null);
        self::assertSame('actor-1', $tokens->readSubmission($key)['actor']);
        self::assertSame('root-uuid', $tokens->readSubmission($key)['rootUuid']);
        self::assertSame('parent-uuid', $tokens->readSubmission($tokens->issueSubmission('a', 't', 'r', 'parent-uuid'))['parentUuid']);
        $cursor = $tokens->issueCursor(['offset' => 'opaque']);
        self::assertSame('opaque', $tokens->readCursor($cursor)['offset']);

        foreach ([$key.'x', $cursor.'x'] as $bad) {
            try { $tokens->readSubmission($bad); self::fail('Tampering must be refused.'); }
            catch (EngagementException) { self::addToAssertionCount(1); }
        }
        try { (new EngagementTokens($this->keyring(), 'install-b', static fn(): int => $now))->readSubmission($key); self::fail('Installation mismatch must be refused.'); }
        catch (EngagementException) { self::addToAssertionCount(1); }

        $now += EngagementTokens::SUBMISSION_TTL;
        try { $tokens->readSubmission($key); self::fail('Expired key must be refused.'); }
        catch (EngagementException $e) { self::assertSame('SUBMISSION_EXPIRED', $e->errorCode); }
    }

    private function keyring(): ApplicationMasterKeyring
    {
        $registry = new ApplicationMasterPurposeRegistry();
        foreach ([EngagementTokens::SUBMISSION_PURPOSE => EngagementTokens::SUBMISSION_TTL, EngagementTokens::CURSOR_PURPOSE => EngagementTokens::CURSOR_TTL] as $purpose => $ttl) {
            $registry->register(new ApplicationMasterPurposePolicy($purpose, 'waaseyaa/engagement', ApplicationMasterPurposeStrategy::ReencryptCiphertext, $ttl, $ttl, EngagementEphemeralTokenRekeyAdapter::ID, 'tokens-expire-naturally'));
        }
        $registry->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new class implements SecretProviderInterface {
            public function id(): string { return 'engagement-test-master'; }
            public function resolve(SecretReference $reference): SensitiveValue { return SensitiveValue::fromBytes(hash('sha256', $reference->identifier(), true), SecretClass::ApplicationMaster, $reference->identifier()); }
        });
        $resolver->allow('engagement-test-master', ApplicationMasterKeyring::PACKAGE, SecretClass::ApplicationMaster, ApplicationMasterKeyring::MASTER_PURPOSE, ['testing']);
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();
        return ApplicationMasterKeyring::fromReferences($resolver, 1, SecretReference::create('engagement-test-master', 'master-v1', SecretClass::ApplicationMaster, ApplicationMasterKeyring::MASTER_PURPOSE), [], $registry);
    }
}
