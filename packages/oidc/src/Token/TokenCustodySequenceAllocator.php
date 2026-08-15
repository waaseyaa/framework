<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

/** Monotonic DB-02 authority for immutable opaque-token custody inventories. @api */
final class TokenCustodySequenceAllocator
{
    public const string ACCESS = 'access';
    public const string REFRESH = 'refresh';

    private const string TABLE = 'oidc_token_custody_sequence';

    private bool $schemaVerified = false;

    public function __construct(private readonly DatabaseInterface $database) {}

    public function allocate(string $tokenKind): int
    {
        if (!in_array($tokenKind, [self::ACCESS, self::REFRESH], true)) {
            throw new \InvalidArgumentException('OIDC custody sequence kinds are closed.');
        }
        $this->assertSchemaAvailable();
        $next = null;
        foreach ($this->database->select(self::TABLE)
            ->fields(self::TABLE, ['next_sequence'])
            ->condition('token_kind', $tokenKind)
            ->execute() as $row) {
            $next = $row['next_sequence'] ?? null;
            break;
        }
        if ((!is_int($next) && (!is_string($next) || preg_match('/^[1-9][0-9]*$/D', $next) !== 1))
            || (int) $next < 1) {
            throw new \RuntimeException('OIDC custody sequence authority is missing or malformed.');
        }
        $sequence = (int) $next;
        $updated = $this->database->update(self::TABLE)
            ->fields(['next_sequence' => $sequence + 1])
            ->condition('token_kind', $tokenKind)
            ->condition('next_sequence', $sequence)
            ->execute();
        if ($updated !== 1) {
            throw new \RuntimeException('OIDC custody sequence allocation conflicted.');
        }

        return $sequence;
    }

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }
        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['token_kind', 'next_sequence'],
            'waaseyaa/oidc:2026_08_15_000008_oidc_application_master_custody',
        );
        $this->schemaVerified = true;
    }
}
