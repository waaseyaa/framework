<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Waaseyaa\FrankenPhp\Binary\BinaryResolver;

/**
 * `dev` — the development server. Resolves the FrankenPHP binary to an absolute
 * path and execs it in the foreground:
 *
 *     frankenphp php-server --root public --listen 127.0.0.1:8080
 *
 * This command runs under SYSTEM PHP (invoked via `composer run dev` →
 * `@php vendor/bin/waaseyaa dev`). It exec's the resolved frankenphp binary BY
 * FULL PATH with no shell (proc_open array form) and NEVER modifies PATH, so
 * FrankenPHP's bundled OpenSSL-disabled php.exe can never shadow system PHP. The
 * child inherits the console's STDIN/STDOUT/STDERR, so output streams live and
 * Ctrl-C reaches the server and stops it.
 *
 * If no binary is found, it prints the one command that fixes it and (when
 * interactive) offers to run `frankenphp:install` immediately.
 *
 * Override the listen address with WAASEYAA_DEV_LISTEN (e.g. 0.0.0.0:9000) and
 * the binary with FRANKENPHP_BIN.
 */
final class DevCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct('dev');
    }

    protected function configure(): void
    {
        $this->setDescription('Serve the app with FrankenPHP (auto-installs the binary on first run). Set WAASEYAA_DEV_LISTEN to override host:port.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isWindows = \PHP_OS_FAMILY === 'Windows';

        $envBin = getenv('FRANKENPHP_BIN');
        $home = (string) getenv($isWindows ? 'USERPROFILE' : 'HOME');

        try {
            $binary = BinaryResolver::resolve(
                $envBin === false || $envBin === '' ? null : $envBin,
                BinaryResolver::managedPath($this->projectRoot, $isWindows),
                $home,
                $isWindows,
                static fn(string $path): bool => is_file($path),
                static fn(): ?string => BinaryResolver::lookupOnPath($isWindows),
            );
        } catch (\RuntimeException $e) {
            // FRANKENPHP_BIN set but missing — a typo; fail loudly.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($binary === null) {
            $binary = $this->resolveMissingBinary($io, $input->isInteractive());
            if ($binary === null) {
                return Command::FAILURE;
            }
        }

        return $this->serve($io, $binary);
    }

    /**
     * Handle the not-found case: print the one fix, and (interactively) offer to
     * run the install now. Returns the installed binary path, or null to abort.
     */
    private function resolveMissingBinary(SymfonyStyle $io, bool $interactive): ?string
    {
        $io->warning(BinaryResolver::notFoundMessage());

        if ($interactive && $io->confirm('Download the FrankenPHP binary now?', true)) {
            try {
                // `dev`'s auto-install always fails closed on an unverifiable checksum
                // (no --allow-unverified opt-out here, deliberately — this is the
                // "just works" inner-loop path; an operator who wants to accept the
                // supply-chain risk runs `frankenphp:install --allow-unverified`
                // explicitly instead).
                $outcome = InstallCommand::performInstall($this->projectRoot, force: false, version: null, output: $io);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                $io->note('Run `frankenphp:install --allow-unverified` to accept this risk explicitly, or retry once the GitHub API is reachable.');

                return null;
            }
            $io->success(sprintf('Installed FrankenPHP at %s.', $outcome->path));

            return $outcome->path;
        }

        $io->note(sprintf('Run `%s`, then `composer run dev` again.', BinaryResolver::installHint()));

        return null;
    }

    private function serve(SymfonyStyle $io, string $binary): int
    {
        $docroot = rtrim($this->projectRoot, '\\/') . '/public';
        $listen = getenv('WAASEYAA_DEV_LISTEN');
        $listen = is_string($listen) && $listen !== '' ? $listen : '127.0.0.1:8080';

        // Preflight: refuse to launch into an address that is already bound.
        // Without this, FrankenPHP prints a buried "bind: address already in use"
        // and exits IMMEDIATELY — but only AFTER we have already printed
        // "Serving … http://…", so a stale/orphaned dev server (this launcher
        // does not kill FrankenPHP on a non-graceful parent exit) makes a fresh
        // `composer run dev` look like it started while the browser gets
        // ERR_CONNECTION_REFUSED. Detecting the conflict up front turns that into
        // one clear, actionable line and a non-zero exit — and we never print a
        // misleading "Serving" for a server that cannot bind.
        if (self::listenAddressInUse($listen)) {
            $io->error(sprintf(
                "Cannot start FrankenPHP: %s is already in use.\n"
                . 'A dev server is probably already running — likely an orphaned frankenphp from a previous run '
                . '(closing the terminal or force-killing the launcher leaves the child holding the port).',
                $listen,
            ));
            $io->note($this->portReleaseHint($listen));

            return Command::FAILURE;
        }

        // Array form bypasses the shell entirely: the binary runs by full path,
        // no PATH resolution, no escaping pitfalls — identical on POSIX, cmd,
        // PowerShell, and Git Bash.
        $command = [$binary, 'php-server', '--root', $docroot, '--listen', $listen];

        $io->writeln(sprintf(
            "Serving <info>%s</info> with FrankenPHP → <info>http://%s</info>  (Ctrl+C to stop)\n  binary: %s",
            $docroot,
            $listen,
            $binary,
        ));

        // Inherit the console's streams so output is live and Ctrl-C reaches the
        // server. No pipes: the child shares our STDIN/STDOUT/STDERR. proc_close
        // blocks for the life of the server, keeping FrankenPHP in the foreground
        // and surfacing its stderr live (no silent detach).
        $process = proc_open($command, [\STDIN, \STDOUT, \STDERR], $pipes);
        if (!\is_resource($process)) {
            $io->error('Failed to launch FrankenPHP.');

            return Command::FAILURE;
        }

        return proc_close($process);
    }

    /**
     * Whether a server is already accepting connections on the dev listen
     * address — in which case FrankenPHP's bind would fail with EADDRINUSE.
     *
     * This is a CONNECT probe, not a bind probe: on Windows PHP's
     * `stream_socket_server` sets SO_REUSEADDR, so a test bind would happily
     * coexist with the orphan and miss it — yet FrankenPHP (which does not set
     * SO_REUSEADDR) still fails. A successful TCP connect proves something is
     * listening there; connection-refused proves the address is free. For a
     * wildcard host (0.0.0.0 / ::) the probe targets loopback, where the
     * conflicting dev server is reachable.
     *
     * Pure and injectable: tests pass a $probe so the contract is verified
     * without real sockets. Returns false for an unparseable address (let
     * FrankenPHP validate it) so the preflight never blocks a launch it cannot
     * reason about.
     *
     * @param (callable(string $host, int $port): bool)|null $probe returns true when something is listening (in use)
     */
    public static function listenAddressInUse(string $listen, ?callable $probe = null): bool
    {
        [$host, $port] = self::splitListen($listen);
        if ($port === null) {
            return false;
        }

        $probe ??= static function (string $h, int $p): bool {
            $connectHost = ($h === '0.0.0.0' || $h === '::' || $h === '[::]') ? '127.0.0.1' : $h;
            $errno = 0;
            $errstr = '';
            $conn = @stream_socket_client("tcp://{$connectHost}:{$p}", $errno, $errstr, 0.5);
            if ($conn === false) {
                return false;
            }
            fclose($conn);

            return true;
        };

        return $probe($host, $port);
    }

    /**
     * Split a `host:port` listen string into [host, port]. A bare numeric value
     * is treated as a port on localhost; an empty host (":8080") becomes
     * localhost. Port is null when it cannot be parsed.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitListen(string $listen): array
    {
        $pos = strrpos($listen, ':');
        if ($pos === false) {
            return ctype_digit($listen) ? ['127.0.0.1', (int) $listen] : [$listen, null];
        }

        $host = substr($listen, 0, $pos);
        $portStr = substr($listen, $pos + 1);
        if ($host === '') {
            $host = '127.0.0.1';
        }

        return ctype_digit($portStr) ? [$host, (int) $portStr] : [$host, null];
    }

    private function portReleaseHint(string $listen): string
    {
        [, $port] = self::splitListen($listen);
        $port ??= 8080;

        return \PHP_OS_FAMILY === 'Windows'
            ? sprintf('Find and stop it:  netstat -ano | findstr :%d   then   taskkill /PID <pid> /F', $port)
            : sprintf('Find and stop it:  lsof -ti tcp:%d | xargs kill', $port);
    }
}
