<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Waaseyaa\Auth\Token\Bearer\BearerTokenRecord;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreException;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;

/**
 * The `bearer-token:*` operator lifecycle commands (#2177 F3).
 *
 * Owned by `waaseyaa/cli` (L6); the auth package exposes only the durable
 * credential store consumed by these operator-facing commands.
 *
 * The issuance/rotation handlers are the ONLY place a bearer secret is ever
 * written out, and only to the operator's console — the store persists a hash,
 * `bearer-token:list` shows fingerprints.
 */
final readonly class BearerTokenConsoleCommands
{
    /**
     * @param \Closure(): BearerTokenStoreInterface $store Lazy store resolver —
     *        resolution happens inside a running command, so `list`ing commands
     *        never touches the database.
     */
    public function __construct(
        private \Closure $store,
    ) {}

    /** @return iterable<\Symfony\Component\Console\Command\Command> */
    public function commands(): iterable
    {
        yield $this->issueCommand();
        yield $this->listCommand();
        yield $this->rotateCommand();
        yield $this->revokeCommand();
    }

    private function issueCommand(): object
    {
        return new \Waaseyaa\CLI\Command\HandlerCommand(
            name: 'bearer-token:issue',
            description: 'Issue a durable bearer token for an account. The secret is shown ONCE and never stored.',
            handler: function (object $io): int {
                $uid = $io->argument('account-uid');
                if (!\is_string($uid) || \preg_match('/^[1-9][0-9]{0,18}$/', $uid) !== 1) {
                    $io->error('account-uid must be a positive integer.');

                    return 2;
                }

                $ttl = $io->option('ttl');

                return $this->run($io, function () use ($io, $uid, $ttl): int {
                    $issued = ($this->store)()->issue(
                        accountUid: (int) $uid,
                        audience: (string) $io->option('audience'),
                        scopes: (array) $io->option('scope'),
                        ttlSeconds: \is_string($ttl) && $ttl !== '' ? (int) $ttl : null,
                        label: (string) $io->option('label'),
                    );

                    $this->printRecord($io, $issued->record);
                    $this->printSecret($io, $issued->secret);

                    return 0;
                });
            },
            arguments: [
                new \Waaseyaa\CLI\Command\HandlerArgument(
                    name: 'account-uid',
                    mode: \Waaseyaa\CLI\Command\HandlerArgumentMode::Required,
                    description: 'Uid of the real account that owns the token.',
                ),
            ],
            options: [
                new \Waaseyaa\CLI\Command\HandlerOption(
                    name: 'scope',
                    mode: \Waaseyaa\CLI\Command\HandlerOptionMode::Array_,
                    description: 'Capability scope the token is limited to (repeatable, at least one).',
                ),
                new \Waaseyaa\CLI\Command\HandlerOption(
                    name: 'audience',
                    mode: \Waaseyaa\CLI\Command\HandlerOptionMode::Required,
                    description: 'Audience the token authenticates at.',
                    default: 'mcp:write',
                ),
                new \Waaseyaa\CLI\Command\HandlerOption(
                    name: 'ttl',
                    mode: \Waaseyaa\CLI\Command\HandlerOptionMode::Required,
                    description: 'Lifetime in seconds (60 .. 7776000; default 2592000 = 30 days).',
                ),
                new \Waaseyaa\CLI\Command\HandlerOption(
                    name: 'label',
                    mode: \Waaseyaa\CLI\Command\HandlerOptionMode::Required,
                    description: 'Operator-facing display label.',
                    default: '',
                ),
            ],
        );
    }

    private function listCommand(): object
    {
        return new \Waaseyaa\CLI\Command\HandlerCommand(
            name: 'bearer-token:list',
            description: 'List durable bearer tokens (fingerprints only — secrets are never retrievable).',
            handler: fn(object $io): int => $this->run($io, function () use ($io): int {
                $records = ($this->store)()->all();
                if ($records === []) {
                    $io->writeln('No bearer tokens.');

                    return 0;
                }

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                foreach ($records as $record) {
                    $status = match (true) {
                        $record->isRevoked() => 'revoked',
                        $record->isExpiredAt($now) => 'expired',
                        default => 'active',
                    };
                    $io->writeln(\sprintf(
                        '%s  %-7s  uid=%d  aud=%s  fp=%s  expires=%s  scopes=[%s]%s%s',
                        $record->id,
                        $status,
                        $record->accountUid,
                        $record->audience,
                        $record->fingerprint,
                        $record->expiresAt->format('Y-m-d H:i:s'),
                        \implode(', ', $record->scopes),
                        $record->label !== '' ? '  label=' . $record->label : '',
                        $record->rotatedFrom !== null ? '  rotated-from=' . $record->rotatedFrom : '',
                    ));
                }

                return 0;
            }),
        );
    }

    private function rotateCommand(): object
    {
        return new \Waaseyaa\CLI\Command\HandlerCommand(
            name: 'bearer-token:rotate',
            description: 'Rotate a bearer token: issue a successor and revoke the predecessor atomically.',
            handler: function (object $io): int {
                $ttl = $io->option('ttl');

                return $this->run($io, function () use ($io, $ttl): int {
                    $issued = ($this->store)()->rotate(
                        (string) $io->argument('token-id'),
                        \is_string($ttl) && $ttl !== '' ? (int) $ttl : null,
                    );

                    $io->writeln(\sprintf('Rotated %s -> %s', (string) $issued->record->rotatedFrom, $issued->record->id));
                    $this->printRecord($io, $issued->record);
                    $this->printSecret($io, $issued->secret);

                    return 0;
                });
            },
            arguments: [
                new \Waaseyaa\CLI\Command\HandlerArgument(
                    name: 'token-id',
                    mode: \Waaseyaa\CLI\Command\HandlerArgumentMode::Required,
                    description: 'Public id of the token to rotate (mbt_...).',
                ),
            ],
            options: [
                new \Waaseyaa\CLI\Command\HandlerOption(
                    name: 'ttl',
                    mode: \Waaseyaa\CLI\Command\HandlerOptionMode::Required,
                    description: 'Successor lifetime in seconds; defaults to the predecessor\'s original lifetime.',
                ),
            ],
        );
    }

    private function revokeCommand(): object
    {
        return new \Waaseyaa\CLI\Command\HandlerCommand(
            name: 'bearer-token:revoke',
            description: 'Durably revoke a bearer token, immediately ending its ability to authenticate.',
            handler: fn(object $io): int => $this->run($io, function () use ($io): int {
                $id = (string) $io->argument('token-id');
                ($this->store)()->revoke($id);
                $io->writeln(\sprintf('Revoked %s.', $id));

                return 0;
            }),
            arguments: [
                new \Waaseyaa\CLI\Command\HandlerArgument(
                    name: 'token-id',
                    mode: \Waaseyaa\CLI\Command\HandlerArgumentMode::Required,
                    description: 'Public id of the token to revoke (mbt_...).',
                ),
            ],
        );
    }

    /**
     * Run a lifecycle action, mapping refusals to exit 2 and store failures to
     * exit 1 — with the sanitized message only, never the driver cause.
     *
     * @param \Closure(): int $action
     */
    private function run(object $io, \Closure $action): int
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        } catch (BearerTokenStoreException $e) {
            $io->error($e->getMessage());

            return 1;
        }
    }

    private function printRecord(object $io, BearerTokenRecord $record): void
    {
        $io->writeln(\sprintf('Token id:    %s', $record->id));
        $io->writeln(\sprintf('Owner uid:   %d', $record->accountUid));
        $io->writeln(\sprintf('Audience:    %s', $record->audience));
        $io->writeln(\sprintf('Scopes:      %s', \implode(', ', $record->scopes)));
        $io->writeln(\sprintf('Fingerprint: %s', $record->fingerprint));
        $io->writeln(\sprintf('Expires:     %s UTC', $record->expiresAt->format('Y-m-d H:i:s')));
    }

    private function printSecret(object $io, string $secret): void
    {
        $io->writeln('');
        $io->writeln('Bearer secret (shown once, never stored — copy it now):');
        $io->writeln('  ' . $secret);
    }
}
