<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;

#[CoversClass(GenerationRefusalException::class)]
#[CoversClass(GenerationViolation::class)]
final class GenerationRefusalExceptionTest extends TestCase
{
    #[Test]
    public function aViolationCarriesTheEnvelopeMembersAndOmitsTheAbsentOnes(): void
    {
        $violation = new GenerationViolation(
            GenerationErrorCode::StalePlan,
            'The reviewed plan no longer matches the project it was evaluated against.',
        );

        self::assertNull($violation->path);
        self::assertNull($violation->pointer);
        self::assertSame([
            'code' => 'GEN005_STALE_PLAN',
            'message' => 'The reviewed plan no longer matches the project it was evaluated against.',
        ], $violation->toArray());
    }

    #[Test]
    public function aViolationEmitsPathAndPointerOnlyWhenDeclared(): void
    {
        $violation = new GenerationViolation(
            GenerationErrorCode::UnsafePath,
            'Generated artifact paths must be safe project-relative paths.',
            path: 'src/Entity/Story.php',
            pointer: '/artifacts/0/path',
        );

        self::assertSame([
            'code' => 'GEN001_UNSAFE_PATH',
            'message' => 'Generated artifact paths must be safe project-relative paths.',
            'path' => 'src/Entity/Story.php',
            'pointer' => '/artifacts/0/path',
        ], $violation->toArray());
    }

    #[Test]
    public function aViolationRefusesAnEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation violation message must not be empty.');

        new GenerationViolation(GenerationErrorCode::Locked, '');
    }

    #[Test]
    public function aViolationRefusesAnEmptyPathRatherThanTreatingItAsAbsent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation violation path must not be empty when declared.');

        new GenerationViolation(GenerationErrorCode::Locked, 'Refused.', path: '');
    }

    #[Test]
    public function aViolationAcceptsTheRootJsonPointerAndEscapedTokens(): void
    {
        self::assertSame(
            ['code' => 'GEN008_LOCKED', 'message' => 'Root.', 'pointer' => ''],
            (new GenerationViolation(GenerationErrorCode::Locked, 'Root.', pointer: ''))->toArray(),
        );
        self::assertSame(
            ['code' => 'GEN008_LOCKED', 'message' => 'Escaped.', 'pointer' => '/a~1b/~0c'],
            (new GenerationViolation(GenerationErrorCode::Locked, 'Escaped.', pointer: '/a~1b/~0c'))->toArray(),
        );
    }

    #[Test]
    #[DataProvider('invalidJsonPointers')]
    public function aViolationRefusesAPointerThatIsNotJsonPointerSyntax(string $pointer): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation violation pointer must be a valid JSON Pointer when declared.');

        new GenerationViolation(GenerationErrorCode::Locked, 'Refused.', pointer: $pointer);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJsonPointers(): iterable
    {
        yield 'missing root slash' => ['artifacts/0/path'];
        yield 'invalid tilde escape' => ['/artifacts/~2/path'];
        yield 'dangling tilde' => ['/artifacts/~'];
    }

    #[Test]
    public function theExceptionIsARuntimeExceptionCarryingItsViolations(): void
    {
        $violations = [
            new GenerationViolation(GenerationErrorCode::CollisionRefused, 'An unmanaged file blocks the target.', path: 'src/Entity/Story.php'),
            new GenerationViolation(GenerationErrorCode::SymlinkRejected, 'A path component resolves through a symlink.', path: 'src/Entity'),
        ];

        $exception = new GenerationRefusalException('scaffold:content-type:story', $violations);

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertInstanceOf(\Throwable::class, $exception);
        self::assertSame('scaffold:content-type:story', $exception->source);
        self::assertSame($violations, $exception->violations);
    }

    #[Test]
    public function itsMessageNamesTheFirstRefusalAndItsNativeCodeStaysZero(): void
    {
        $exception = new GenerationRefusalException('site', [
            new GenerationViolation(GenerationErrorCode::Locked, 'A concurrent initialization holds the project lock.'),
        ]);

        self::assertSame('site GEN008_LOCKED: A concurrent initialization holds the project lock.', $exception->getMessage());
        self::assertSame(0, $exception->getCode(), 'The GEN0xx id is the code; the native integer code stays unused.');
    }

    #[Test]
    public function itsMessageNamesThePathWhenTheFirstRefusalHasOne(): void
    {
        $exception = new GenerationRefusalException('site', [
            new GenerationViolation(GenerationErrorCode::UnsafePath, 'Refused.', path: '../outside.txt'),
        ]);

        self::assertSame('site GEN001_UNSAFE_PATH at ../outside.txt: Refused.', $exception->getMessage());
    }

    #[Test]
    public function itChainsAPreviousThrowable(): void
    {
        $previous = new \LogicException('underlying');
        $exception = new GenerationRefusalException(
            'site',
            [new GenerationViolation(GenerationErrorCode::Locked, 'Refused.')],
            $previous,
        );

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function itRefusesAnEmptySourceOrAnEmptyViolationList(): void
    {
        try {
            new GenerationRefusalException('', [new GenerationViolation(GenerationErrorCode::Locked, 'Refused.')]);
            self::fail('An empty source must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Generation refusal source must not be empty.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation refusal must carry at least one violation.');

        new GenerationRefusalException('site', []);
    }

    #[Test]
    public function itRefusesASparseViolationListRatherThanSerializingAJsonObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation refusal violations must be a list.');

        new GenerationRefusalException('site', [
            0 => new GenerationViolation(GenerationErrorCode::Locked, 'Held.'),
            2 => new GenerationViolation(GenerationErrorCode::StalePlan, 'Moved.'),
        ]);
    }

    #[Test]
    #[DataProvider('invalidViolationLists')]
    public function itRefusesNonViolationValuesBeforeReadingOrSerializingThem(array $violations): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation refusal violations must contain only GenerationViolation values.');

        new GenerationRefusalException('site', $violations);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidViolationLists(): iterable
    {
        yield 'invalid first value' => [[new \stdClass()]];
        yield 'invalid later value' => [[
            new GenerationViolation(GenerationErrorCode::Locked, 'Held.'),
            new \stdClass(),
        ]];
    }

    #[Test]
    public function itSerializesEveryViolationInDeclaredOrder(): void
    {
        $exception = new GenerationRefusalException('site', [
            new GenerationViolation(GenerationErrorCode::UnitPathConflict, 'Two units claim one path.', path: 'src/Entity/Story.php'),
            new GenerationViolation(GenerationErrorCode::UndeclaredUnitRetirement, 'A recorded row disappeared.', pointer: '/retires'),
        ]);

        self::assertSame([
            ['code' => 'GEN010_UNIT_PATH_CONFLICT', 'message' => 'Two units claim one path.', 'path' => 'src/Entity/Story.php'],
            ['code' => 'GEN009_UNDECLARED_UNIT_RETIREMENT', 'message' => 'A recorded row disappeared.', 'pointer' => '/retires'],
        ], $exception->toArray());
    }
}
