<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tooling\SurfaceChangeAuthorization;

require_once __DIR__ . '/../../tools/lib/SurfaceChangeAuthorization.php';

#[CoversNothing]
final class SurfaceChangeAuthorizationTest extends TestCase
{
    private const OLD = 'Waaseyaa\\Old\\SharedName';
    private const COLLISION = 'Waaseyaa\\Other\\SharedName';
    private const NEW = 'Waaseyaa\\New\\Replacement';

    #[Test]
    public function historical_and_narrative_mentions_never_authorize_removal(): void
    {
        $changelog = <<<'MD'
            ## [Unreleased]

            ### Added

            - `Waaseyaa\Old\SharedName`

            ### Changed

            - Public surface removal: `Waaseyaa\Old\SharedName`

            ### Removed

            - See [SharedName](https://example.com/Waaseyaa%5COld%5CSharedName) in prose.

            ## [1.2.3] - 2026-01-01

            ### Removed

            - Public surface removal: `Waaseyaa\Old\SharedName`
            MD;

        $result = SurfaceChangeAuthorization::parse($changelog, [
            '- `Waaseyaa\Old\SharedName`',
            '- Public surface removal: `Waaseyaa\Old\SharedName`',
            '- See [SharedName](https://example.com/Waaseyaa%5COld%5CSharedName) in prose.',
        ]);

        self::assertSame([], array_keys($result['removals']));
        self::assertSame([], $result['renames']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('must be under ### Removed', $result['errors'][0]);
    }

    #[Test]
    public function exact_new_unreleased_directives_authorize_only_their_fqcn(): void
    {
        $removal = '- Public surface removal: `Waaseyaa\Old\SharedName`';
        $deprecation = '- Public surface deprecation: `Waaseyaa\Other\SharedName`';
        $changelog = "## [Unreleased]\n\n### Deprecated\n\n{$deprecation}\n\n### Removed\n\n{$removal}\n";

        $result = SurfaceChangeAuthorization::parse($changelog, [$removal, $deprecation]);

        self::assertSame([self::OLD], array_keys($result['removals']));
        self::assertSame([self::COLLISION], array_keys($result['deprecations']));
        self::assertArrayNotHasKey(self::COLLISION, $result['removals']);
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function stale_unreleased_directive_is_not_current_authorization(): void
    {
        $line = '- Public surface removal: `Waaseyaa\Old\SharedName`';
        $changelog = "## [Unreleased]\n\n### Removed\n\n{$line}\n";

        $result = SurfaceChangeAuthorization::parse($changelog, []);

        self::assertSame([], array_keys($result['removals']));
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function rename_is_exact_directional_and_machine_verifiable(): void
    {
        $line = '- Public surface rename: `Waaseyaa\Old\SharedName` -> `Waaseyaa\New\Replacement`';
        $changelog = "## [Unreleased]\n\n### Removed\n\n{$line}\n";

        $result = SurfaceChangeAuthorization::parse($changelog, [$line]);

        self::assertSame([self::OLD => self::NEW], $result['renames']);
        self::assertArrayNotHasKey(self::COLLISION, $result['renames']);
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function malformed_short_names_and_conflicting_directives_fail_closed(): void
    {
        $short = '- Public surface removal: `SharedName`';
        $removal = '- Public surface removal: `Waaseyaa\Old\SharedName`';
        $rename = '- Public surface rename: `Waaseyaa\Old\SharedName` -> `Waaseyaa\New\Replacement`';
        $changelog = "## [Unreleased]\n\n### Removed\n\n{$short}\n{$removal}\n{$rename}\n";

        $result = SurfaceChangeAuthorization::parse($changelog, [$short, $removal, $rename]);

        self::assertCount(2, $result['errors']);
        self::assertStringContainsString('malformed', $result['errors'][0]);
        self::assertStringContainsString('duplicate or conflicting', $result['errors'][1]);
    }

    #[Test]
    public function base_map_delta_catches_governed_concrete_final_removal(): void
    {
        $base = [
            'Waaseyaa\Concrete\PublicFinal' => 'public',
            self::COLLISION => 'public',
        ];
        $candidate = [self::COLLISION => 'public'];

        self::assertSame(
            ['Waaseyaa\Concrete\PublicFinal'],
            SurfaceChangeAuthorization::removedMapEntries($base, $candidate),
        );
    }

    #[Test]
    public function public_disposition_downgrade_requires_separate_deprecation_authority(): void
    {
        $base = [self::OLD => 'public', self::COLLISION => 'public'];
        $candidate = [self::OLD => 'internal', self::COLLISION => 'public'];

        self::assertSame([self::OLD], SurfaceChangeAuthorization::publicDowngrades($base, $candidate));
    }
}
