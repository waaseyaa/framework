<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Testing\Factory\EntityTypeFactory;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * R13 WP1 (audit A11): GenericAdminSurfaceHost::list() applied caller-supplied
 * filter/sort field names against the raw entity value with no field-level
 * access check. An account holding only the low-tier "access user profiles"
 * permission could filter the auto-cataloged `user` list on a Forbidden
 * credential field (`pass`, or the 2FA fields, all Forbidden-for-everyone via
 * UserAccessPolicy::fieldAccess) and read per-row presence/absence as a
 * one-bit oracle, reconstructing the value one probe at a time. The REST
 * sibling closes exactly this via JsonApiController::validateQueryFields
 * ("audit R2 WP1"); this file is the admin-surface parity guard.
 *
 * The fix is two layers:
 *  (a) a structural allowlist mirroring validateQueryFields(), rejecting the
 *      whole request for an unknown/ALWAYS_INTERNAL/`internal`-setting field;
 *  (b) per-entity field-view access (EntityAccessHandler::checkFieldAccess())
 *      enforced inside applyFilter() and the sort comparator, needed because
 *      a field can be Forbidden only for *some* entities (e.g. classification/
 *      clearance-gated fields), which a static allowlist cannot express.
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostFieldAccessOracleTest extends TestCase
{
    private const string KNOWN_HASH = '$2y$10$abcdefghijklmnopqrstuvKNOWNHASHFRAGMENTXYZ123456789';
    private const string KNOWN_2FA_SECRET = 'JBSWY3DPEHPK3PXPKNOWNSECRETVALUE';

    /**
     * Build a host wired with the REAL EntityAccessHandler + UserAccessPolicy
     * (production credential-field policy), a REAL `user` EntityType (so field
     * definitions/keys/settings, including `two_factor_secret`'s `internal`
     * setting, come from the real User class, not a test double), and a
     * single victim User entity holding a known password hash and 2FA secret.
     *
     * The session account holds ONLY "access user profiles": the low-tier
     * permission from the exploit report. The host is configured with that
     * same permission as its admin-surface entry gate (a plausible real
     * deployment: an app gating its user-directory admin view on this
     * permission rather than the blanket "administer content"), so the
     * scenario is reachable at all while staying far from "administer users"
     * (which UserAccessPolicy treats as a bypass for everything EXCEPT
     * credential/2FA fields: those are Forbidden for everyone, including
     * admins, per UserAccessPolicy::CREDENTIAL_FIELDS).
     */
    private function hostWithVictim(EntityInterface $victim): GenericAdminSurfaceHost
    {
        $userType = EntityType::fromClass(User::class);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($userType);
        $etm->method('resolveFieldDefinitions')->willReturn($userType->getFieldDefinitions());

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$victim]);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $accessHandler = new EntityAccessHandler([new UserAccessPolicy()]);

        $host = new GenericAdminSurfaceHost($etm, $accessHandler, adminPermission: 'access user profiles');
        $this->resolveViewerSession($host, 'access user profiles');

        return $host;
    }

    private function makeVictim(): User
    {
        return new User([
            'uid' => 42,
            'uuid' => 'victim-uuid',
            'name' => 'victim',
            'mail' => 'victim@example.com',
            'status' => 1,
            'roles' => [],
            'permissions' => [],
            'pass' => self::KNOWN_HASH,
            'two_factor_secret' => self::KNOWN_2FA_SECRET,
        ]);
    }

    // -----------------------------------------------------------------
    // 1a: STRUCTURAL/credential: filter on `pass`.
    // -----------------------------------------------------------------

    #[Test]
    public function filtering_on_pass_field_no_longer_reveals_a_presence_oracle(): void
    {
        $host = $this->hostWithVictim($this->makeVictim());

        // A matching substring of the real hash: on unfixed code this returns
        // the victim row (bit = 1). A non-matching substring returns nothing
        // (bit = 0): together, a working oracle an attacker can walk one
        // probe at a time to reconstruct the whole hash.
        $matching = $host->list('user', new SurfaceQuery(
            filters: [['field' => 'pass', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => substr(self::KNOWN_HASH, 4, 10)]],
        ));
        $nonMatching = $host->list('user', new SurfaceQuery(
            filters: [['field' => 'pass', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => 'not-in-the-hash-xyz']],
        ));

        // Fixed behaviour: the filter is structurally rejected outright, an
        // identical (error) response regardless of whether the guessed
        // substring matches, so no oracle bit is observable.
        $this->assertFalse($matching->ok, 'A filter on the pass field must be rejected.');
        $this->assertSame(400, $matching->error['status'] ?? null);
        $this->assertFalse($nonMatching->ok, 'A filter on the pass field must be rejected.');
        $this->assertSame(400, $nonMatching->error['status'] ?? null);
    }

    #[Test]
    public function sorting_by_pass_field_is_rejected(): void
    {
        $host = $this->hostWithVictim($this->makeVictim());

        $result = $host->list('user', new SurfaceQuery(sortField: 'pass'));

        $this->assertFalse($result->ok, 'A sort on the pass field must be rejected.');
        $this->assertSame(400, $result->error['status'] ?? null);
    }

    // -----------------------------------------------------------------
    // 1b: the real production 2FA field: Forbidden via UserAccessPolicy,
    // and NOT in ResourceSerializer::ALWAYS_INTERNAL_FIELDS (that list is
    // only ['pass', 'password', 'password_hash']). It happens to also carry
    // the `internal` field-setting, so the structural allowlist closes it
    // too, but we independently confirm the field-access layer forbids it
    // as well, proving layer (b) is not a no-op for this field (defense in
    // depth: if the `internal` setting were ever dropped, layer (b) alone
    // would still hold the line).
    // -----------------------------------------------------------------

    #[Test]
    public function filtering_on_two_factor_secret_no_longer_reveals_a_presence_oracle(): void
    {
        $host = $this->hostWithVictim($this->makeVictim());

        $matching = $host->list('user', new SurfaceQuery(
            filters: [['field' => 'two_factor_secret', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => substr(self::KNOWN_2FA_SECRET, 0, 8)]],
        ));
        $nonMatching = $host->list('user', new SurfaceQuery(
            filters: [['field' => 'two_factor_secret', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => 'totally-different']],
        ));

        $this->assertFalse($matching->ok, 'A filter on two_factor_secret must be rejected.');
        $this->assertFalse($nonMatching->ok, 'A filter on two_factor_secret must be rejected.');
    }

    #[Test]
    public function user_access_policy_forbids_two_factor_secret_at_the_field_access_layer(): void
    {
        // Confirms layer (b) independently of layer (a): even without the
        // structural allowlist's help, UserAccessPolicy::fieldAccess() alone
        // closes this field for a low-tier viewer.
        $accessHandler = new EntityAccessHandler([new UserAccessPolicy()]);
        $viewer = $this->viewerAccount('access user profiles');

        $result = $accessHandler->checkFieldAccess($this->makeVictim(), 'two_factor_secret', 'view', $viewer);

        $this->assertTrue($result->isForbidden());
    }

    // -----------------------------------------------------------------
    // 1b (isolated): a field a STATIC structural allowlist alone cannot
    // close: Forbidden only for SOME entities of the type (the
    // classification/clearance-gated shape named in the fix design). This
    // proves layer (b) independent of layer (a). `body` here is a perfectly
    // ordinary declared string field, not internal, not in
    // ALWAYS_INTERNAL_FIELDS, so the structural allowlist alone would let it
    // straight through.
    // -----------------------------------------------------------------

    #[Test]
    public function per_entity_forbidden_field_is_excluded_from_filter_results_not_leaked(): void
    {
        [$etm, $storage] = $this->docTypeWithEntities();

        $classified = $this->docEntity('1', 'classified-doc', 'THE-SECRET-PAYLOAD', true);
        $public = $this->docEntity('2', 'public-doc', 'nothing-interesting', false);
        $storage->method('loadMultiple')->willReturn([$classified, $public]);

        $accessHandler = new EntityAccessHandler([$this->perEntityBodyPolicy()]);
        $host = new GenericAdminSurfaceHost($etm, $accessHandler, adminPermission: 'access docs');
        $this->resolveViewerSession($host, 'access docs');

        // Search for the exact secret payload. Pre-fix this returns the
        // classified doc (oracle: "yes, that substring is in there").
        // Post-fix the classified entity is excluded regardless of match.
        $result = $host->list('doc', new SurfaceQuery(
            filters: [['field' => 'body', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => 'SECRET']],
        ));

        $this->assertTrue($result->ok);
        $this->assertSame(0, $result->data['total'], 'The classified entity must not match a filter on its Forbidden field.');
    }

    #[Test]
    public function per_entity_forbidden_sort_is_rejected_value_independently(): void
    {
        // Two variants of the same scenario, differing ONLY in the classified
        // entity's real (Forbidden) `body` value: one starts with 'a' (sorts
        // ahead of the public entity's 'mmm-...' value if read), the other
        // with 'z' (sorts behind it if read), both lowercase, so the
        // comparison is a plain byte-order check, not a case-fold surprise.
        // If sorting ever read the Forbidden value, the two variants would
        // produce DIFFERENT orderings: a value-derived ordering oracle.
        // Because the value must never be read, the resulting order is
        // identical in both variants.
        $statusWithLowValue = $this->sortStatusForClassifiedBody('aaa-secret-value');
        $statusWithHighValue = $this->sortStatusForClassifiedBody('zzz-secret-value');

        $this->assertSame(
            $statusWithLowValue,
            $statusWithHighValue,
            "Sort rejection must not depend on the Forbidden field's real value.",
        );
        $this->assertSame(400, $statusWithLowValue);
    }

    private function sortStatusForClassifiedBody(string $classifiedBody): int
    {
        [$etm, $storage] = $this->docTypeWithEntities();

        $classified = $this->docEntity('1', 'classified-doc', $classifiedBody, true);
        $public = $this->docEntity('2', 'public-doc', 'mmm-public-value', false);
        $storage->method('loadMultiple')->willReturn([$classified, $public]);

        $accessHandler = new EntityAccessHandler([$this->perEntityBodyPolicy()]);
        $host = new GenericAdminSurfaceHost($etm, $accessHandler, adminPermission: 'access docs');
        $this->resolveViewerSession($host, 'access docs');

        $result = $host->list('doc', new SurfaceQuery(sortField: 'body', sortDirection: 'ASC'));
        $this->assertFalse($result->ok);

        return $result->error['status'];
    }

    // -----------------------------------------------------------------
    // 1d: POSITIVE CONTROL: a legitimately-readable field still filters
    // and sorts normally for the low-tier account.
    // -----------------------------------------------------------------

    #[Test]
    public function filtering_and_sorting_by_a_legitimate_field_still_works(): void
    {
        $victim = $this->makeVictim();
        $other = new User([
            'uid' => 7,
            'uuid' => 'other-uuid',
            'name' => 'alpha',
            'mail' => 'alpha@example.com',
            'status' => 1,
            'roles' => [],
            'permissions' => [],
            'pass' => 'irrelevant',
        ]);

        $userType = EntityType::fromClass(User::class);
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($userType);
        $etm->method('resolveFieldDefinitions')->willReturn($userType->getFieldDefinitions());

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$victim, $other]);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $accessHandler = new EntityAccessHandler([new UserAccessPolicy()]);
        $host = new GenericAdminSurfaceHost($etm, $accessHandler, adminPermission: 'access user profiles');
        $this->resolveViewerSession($host, 'access user profiles');

        $scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $scope,
            static fn(...$args): AccessResult => AccessResult::allowed(
                'The explicit profile-view test principal may read this fixture projection.',
            ),
        ));
        $principal = new AuthorizationPrincipal(1, true, ['viewer'], ['access user profiles'], 'admin-surface-oracle-test');
        try {
            // Internal mail is structurally unavailable; `name` is the
            // legitimate profile-view field used as the positive control.
            $filtered = $scope->run($principal, fn() => $host->list('user', new SurfaceQuery(
                filters: [['field' => 'name', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'victim']],
            )));
            $this->assertTrue($filtered->ok);
            $this->assertSame(1, $filtered->data['total']);
            $this->assertSame('victim', $filtered->data['entities'][0]['attributes']['name']);

            $sorted = $scope->run($principal, fn() => $host->list('user', new SurfaceQuery(sortField: 'name')));
            $this->assertTrue($sorted->ok);
            $names = array_map(static fn(array $e) => $e['attributes']['name'] ?? null, $sorted->data['entities']);
            $this->assertSame(['alpha', 'victim'], $names);
        } finally {
            EntityReadRuntime::installGuard(null);
        }
    }

    /**
     * @return array{0: EntityTypeManagerInterface&MockObject, 1: EntityStorageInterface&MockObject}
     */
    private function docTypeWithEntities(): array
    {
        $docType = EntityTypeFactory::create(
            id: 'doc',
            fieldDefinitions: [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'body' => ['type' => 'string', 'label' => 'Body'],
                'classified' => ['type' => 'boolean', 'label' => 'Classified'],
            ],
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($docType);
        $etm->method('resolveFieldDefinitions')->willReturn($docType->getFieldDefinitions());

        $storage = $this->createMock(EntityStorageInterface::class);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        return [$etm, $storage];
    }

    private function docEntity(string $id, string $title, string $body, bool $classified): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('doc');
        $entity->method('id')->willReturn($id);
        $entity->method('uuid')->willReturn('uuid-' . $id);
        $entity->method('bundle')->willReturn('doc');
        $entity->method('toArray')->willReturn(['id' => $id, 'title' => $title, 'body' => $body, 'classified' => $classified]);
        $entity->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'id' => $id,
                'title' => $title,
                'body' => $body,
                'classified' => $classified,
                default => null,
            },
        );

        return $entity;
    }

    /**
     * A per-entity field-access policy modeling the classification/clearance
     * shape: the `body` field is Forbidden only on entities flagged
     * `classified`, Neutral (open) otherwise. Written as an anonymous class
     * implementing the intersection type per project testing convention
     * (PHPUnit createMock() cannot mock intersection types).
     */
    private function perEntityBodyPolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'doc';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('Docs are entity-level viewable; only the body field is gated.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function fieldAccess(
                EntityInterface $entity,
                string $fieldName,
                string $operation,
                AccountInterface $account,
            ): AccessResult {
                if ($fieldName === 'body' && $entity->get('classified') === true) {
                    return AccessResult::forbidden('Classified body is not readable.');
                }

                return AccessResult::neutral();
            }
        };
    }

    private function viewerAccount(string $permission): AccountInterface
    {
        return new AuthorizationPrincipal(1, true, ['viewer'], [$permission], 'admin-surface-oracle-test');
    }

    private function resolveViewerSession(GenericAdminSurfaceHost $host, string $permission): void
    {
        $request = Request::create('/');
        $request->attributes->set('_account', $this->viewerAccount($permission));
        $host->resolveSession($request);
    }
}
