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
                $outcome = InstallCommand::performInstall($this->projectRoot, force: false, version: null, output: $io);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

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
        // server. No pipes: the child shares our STDIN/STDOUT/STDERR.
        $process = proc_open($command, [\STDIN, \STDOUT, \STDERR], $pipes);
        if (!\is_resource($process)) {
            $io->error('Failed to launch FrankenPHP.');

            return Command::FAILURE;
        }

        return proc_close($process);
    }
}
