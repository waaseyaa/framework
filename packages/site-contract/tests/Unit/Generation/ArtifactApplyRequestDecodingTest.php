<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

/**
 * ADR-025 D-6.5: apply's input is a document a *later process* reads. A
 * digest-only plan cannot be applied in a second process, so the request
 * carries the plan bytes — which means those bytes must be decodable exactly
 * once, fail-closed, by the contract that defines them, rather than by each
 * consumer's own hand-rolled `json_decode` walk.
 */
#[CoversClass(ArtifactApplyRequest::class)]
final class ArtifactApplyRequestDecodingTest extends TestCase
{
    private const string STATE_DIGEST = 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function theExactEmittedBytesDecodeToTheSameRequest(): void
    {
        $request = $this->request();

        $decoded = ArtifactApplyRequest::fromCanonicalJson($request->canonicalJson(), 'reviewed-request.json');

        self::assertSame($request->toArray(), $decoded->toArray());
        self::assertSame($request->canonicalJson(), $decoded->canonicalJson());
        self::assertSame($request->plan->digest, $decoded->plan->digest, 'The transported plan must hash to the reviewed identity.');
        self::assertSame(
            "<?php\n\nfinal class Story {}\n",
            $decoded->plan->artifacts[0]->content,
            'The artifact bytes must survive transport byte for byte.',
        );
        self::assertSame(0o755, $decoded->plan->artifacts[1]->mode);
        self::assertSame('body', $decoded->plan->artifacts[1]->extensionRegion);
        self::assertSame(['App\\Provider\\StoryServiceProvider'], array_column(array_map(
            static fn(ComposerProviderRegistration $registration): array => $registration->toArray(),
            $decoded->plan->registrations,
        ), 'fqcn'));
        self::assertSame(GenerationUnitDisposition::Seeded, $decoded->plan->disposition);
    }

    #[Test]
    public function aSingleTerminatingNewlineIsTheOnlyAcceptedFramingVariation(): void
    {
        $canonical = $this->request()->canonicalJson();

        self::assertSame(
            $canonical,
            ArtifactApplyRequest::fromCanonicalJson($canonical . "\n", 'reviewed-request.json')->canonicalJson(),
        );
        $this->assertRefused($canonical . "\n\n", 'SITE014_INVALID_VALUE', '/');
        $this->assertRefused(' ' . $canonical, 'SITE014_INVALID_VALUE', '/');
    }

    #[Test]
    public function theReviewedPlanDigestSurvivesDecodingWithoutBeingRederived(): void
    {
        $reviewed = str_repeat('c', 64);
        $request = new ArtifactApplyRequest($this->plan(), $reviewed, self::STATE_DIGEST);

        $decoded = ArtifactApplyRequest::fromCanonicalJson($request->canonicalJson(), 'reviewed-request.json');

        self::assertSame($reviewed, $decoded->planDigest);
        self::assertNotSame($decoded->plan->digest, $decoded->planDigest, 'Decoding must not erase the evidence apply refuses as GEN005 under its lock.');
    }

    #[Test]
    public function nonCanonicalBytesAreRefusedEvenWhenTheyDecodeToTheSameDocument(): void
    {
        $document = $this->request()->toArray();

        $this->assertRefused((string) json_encode($document, JSON_THROW_ON_ERROR), 'SITE014_INVALID_VALUE', '/');
        $this->assertRefused(
            (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'SITE014_INVALID_VALUE',
            '/',
        );
    }

    #[Test]
    public function aDuplicatedMemberIsRefused(): void
    {
        $canonical = $this->request()->canonicalJson();
        $duplicated = str_replace(
            '"project_state_digest":"' . self::STATE_DIGEST . '"',
            '"project_state_digest":"' . self::STATE_DIGEST . '","project_state_digest":"' . self::STATE_DIGEST . '"',
            $canonical,
        );

        self::assertNotSame($canonical, $duplicated);
        $this->assertRefused($duplicated, 'SITE014_INVALID_VALUE', '/');
    }

    #[Test]
    #[DataProvider('corruptDocuments')]
    public function corruptTransportIsRefusedAsAnInvalidDocument(string $bytes): void
    {
        $this->assertRefused($bytes, 'SITE010_INVALID_TYPE', '/');
    }

    /** @return iterable<string, array{string}> */
    public static function corruptDocuments(): iterable
    {
        yield 'truncated' => ['{"schema":"waaseyaa.artifact_apply_request","version":1,"plan"'];
        yield 'empty' => [''];
        yield 'json list' => ['[]'];
        yield 'json scalar' => ['"waaseyaa.artifact_apply_request"'];
    }

    #[Test]
    #[DataProvider('refusedDocuments')]
    public function aMemberThatIsUnknownMissingOrWrongTypedIsRefused(callable $mutate, string $code, string $pointer): void
    {
        $document = $this->request()->toArray();

        $this->assertArrayRefused($mutate($document), $code, $pointer);
    }

    /** @return iterable<string, array{callable, string, string}> */
    public static function refusedDocuments(): iterable
    {
        yield 'unknown request member' => [
            static function (array $document): array {
                $document['reviewed_by'] = 'operator';

                return $document;
            },
            'SITE001_UNKNOWN_KEY',
            '/reviewed_by',
        ];
        yield 'missing request member' => [
            static function (array $document): array {
                unset($document['plan_digest']);

                return $document;
            },
            'SITE011_REQUIRED_KEY',
            '/plan_digest',
        ];
        yield 'wrong-typed version' => [
            static function (array $document): array {
                $document['version'] = '1';

                return $document;
            },
            'SITE010_INVALID_TYPE',
            '/version',
        ];
        yield 'unsupported version' => [
            static function (array $document): array {
                $document['version'] = 2;

                return $document;
            },
            'SITE003_UNSUPPORTED_SCHEMA_VERSION',
            '/version',
        ];
        yield 'foreign schema' => [
            static function (array $document): array {
                $document['schema'] = 'waaseyaa.artifact_plan';

                return $document;
            },
            'SITE014_INVALID_VALUE',
            '/schema',
        ];
        yield 'uppercase plan digest' => [
            static function (array $document): array {
                $document['plan_digest'] = strtoupper($document['plan_digest']);

                return $document;
            },
            'SITE014_INVALID_VALUE',
            '/plan_digest',
        ];
        yield 'non-string project state digest' => [
            static function (array $document): array {
                $document['project_state_digest'] = 0;

                return $document;
            },
            'SITE010_INVALID_TYPE',
            '/project_state_digest',
        ];
        yield 'plan is not a mapping' => [
            static function (array $document): array {
                $document['plan'] = ['waaseyaa.artifact_plan'];

                return $document;
            },
            'SITE010_INVALID_TYPE',
            '/plan',
        ];
        yield 'unknown plan member' => [
            static function (array $document): array {
                $document['plan']['reserved_effects'] = [];

                return $document;
            },
            'SITE001_UNKNOWN_KEY',
            '/plan/reserved_effects',
        ];
        yield 'missing plan member' => [
            static function (array $document): array {
                unset($document['plan']['unit']);

                return $document;
            },
            'SITE011_REQUIRED_KEY',
            '/plan/unit',
        ];
        yield 'unknown plan disposition' => [
            static function (array $document): array {
                $document['plan']['unit']['disposition'] = 'adopted';

                return $document;
            },
            'SITE014_INVALID_VALUE',
            '/plan/unit/disposition',
        ];
        yield 'unknown set evolution' => [
            static function (array $document): array {
                $document['plan']['set_evolution'] = 'destructive';

                return $document;
            },
            'SITE014_INVALID_VALUE',
            '/plan/set_evolution',
        ];
        yield 'missing artifact member' => [
            static function (array $document): array {
                unset($document['plan']['artifacts'][0]['content']);

                return $document;
            },
            'SITE011_REQUIRED_KEY',
            '/plan/artifacts/0/content',
        ];
        yield 'unknown artifact member' => [
            static function (array $document): array {
                $document['plan']['artifacts'][0]['owner'] = 'site';

                return $document;
            },
            'SITE001_UNKNOWN_KEY',
            '/plan/artifacts/0/owner',
        ];
        yield 'wrong-typed artifact content' => [
            static function (array $document): array {
                $document['plan']['artifacts'][0]['content'] = ['<?php'];

                return $document;
            },
            'SITE010_INVALID_TYPE',
            '/plan/artifacts/0/content',
        ];
        yield 'empty artifact content' => [
            static function (array $document): array {
                $document['plan']['artifacts'][0]['content'] = '';

                return $document;
            },
            'SITE012_EMPTY_VALUE',
            '/plan/artifacts/0/content',
        ];
        yield 'non-octal artifact mode' => [
            static function (array $document): array {
                $document['plan']['artifacts'][0]['mode'] = '420';

                return $document;
            },
            'SITE014_INVALID_VALUE',
            '/plan/artifacts/0/mode',
        ];
        yield 'keyed artifact list' => [
            static function (array $document): array {
                $document['plan']['artifacts'] = ['src/Entity/Story.php' => $document['plan']['artifacts'][0]];

                return $document;
            },
            'SITE010_INVALID_TYPE',
            '/plan/artifacts',
        ];
        yield 'registration without an fqcn' => [
            static function (array $document): array {
                $document['plan']['registrations'][0] = ['group' => 'site'];

                return $document;
            },
            'SITE011_REQUIRED_KEY',
            '/plan/registrations/0/fqcn',
        ];
        yield 'duplicate retirement' => [
            static function (array $document): array {
                $document['plan']['retires'] = ['scaffold:legacy', 'scaffold:legacy'];

                return $document;
            },
            'SITE021_DUPLICATE_VALUE',
            '/plan/retires/1',
        ];
    }

    #[Test]
    public function anInvalidNestedPlanIsRefusedByThePlanContractItself(): void
    {
        $document = $this->request()->toArray();
        $document['plan']['artifacts'] = array_reverse($document['plan']['artifacts']);

        $exception = $this->assertArrayRefused($document, 'SITE014_INVALID_VALUE', '/plan');
        self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
        self::assertStringContainsString('sorted by path', (string) $exception->getPrevious()?->getMessage());
    }

    #[Test]
    public function theSourceOfARefusalIsTheSuppliedTransportIdentity(): void
    {
        try {
            ArtifactApplyRequest::fromCanonicalJson('{}', '/tmp/reviewed-request.json');
            self::fail('An empty document must be refused.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame('/tmp/reviewed-request.json', $exception->source);
        }
    }

    /** @param array<string, mixed> $document */
    private function assertArrayRefused(array $document, string $code, string $pointer): SiteManifestValidationException
    {
        try {
            ArtifactApplyRequest::fromArray($document, 'reviewed-request.json');
            self::fail("Expected {$code} at {$pointer}.");
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($code, $exception->violations[0]->code);
            self::assertSame($pointer, $exception->violations[0]->path);

            return $exception;
        }
    }

    private function assertRefused(string $bytes, string $code, string $pointer): void
    {
        try {
            ArtifactApplyRequest::fromCanonicalJson($bytes, 'reviewed-request.json');
            self::fail("Expected {$code} at {$pointer}.");
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($code, $exception->violations[0]->code);
            self::assertSame($pointer, $exception->violations[0]->path);
        }
    }

    private function request(): ArtifactApplyRequest
    {
        $plan = $this->plan();

        return new ArtifactApplyRequest($plan, $plan->digest, self::STATE_DIGEST);
    }

    private function plan(): ArtifactPlan
    {
        return new ArtifactPlan(
            'Example\\Generation\\StoryScaffoldCompiler',
            2,
            'scaffold:content-type:story',
            GenerationUnitDisposition::Seeded,
            'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
            [
                new GeneratedArtifact('src/Entity/Story.php', "<?php\n\nfinal class Story {}\n"),
                new GeneratedArtifact(
                    'templates/story.html.twig',
                    "<article>\n<!-- waaseyaa:extension:start body --><!-- waaseyaa:extension:end body -->\n</article>\n",
                    0o755,
                    'body',
                ),
            ],
            ['scaffold:legacy'],
            [new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'site')],
            ['templates/story.html.twig'],
        );
    }
}
