<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\Preflight\FieldAccessPreflightScanner;

/** @api */
final readonly class FieldAccessPreflightHandler
{
    public function __construct(
        private DatabaseFieldAccessInventoryScanner $liveScanner,
        private EntityTypeManager $entityTypes,
        private FieldAccessPreflightScanner $preflight = new FieldAccessPreflightScanner(),
        private string $projectRoot = '',
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $format = (string) ($io->option('format') ?? 'json');
        if ($format !== 'json') {
            $io->error('field-access:preflight supports only --format=json.');

            return 2;
        }

        $root = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $version = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : 'dev';
        if (is_file($root . '/composer.lock')) {
            $version .= '@' . substr(hash_file('sha256', $root . '/composer.lock'), 0, 16);
        }
        $artifact = $this->loadClassificationArtifact($root);
        $version .= '@classification-' . substr(hash('sha256', json_encode($artifact, JSON_THROW_ON_ERROR)), 0, 16);
        $result = $this->preflight->scan($this->entityTypes, $this->liveScanner->scan($version, $artifact));
        $json = json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $io->write($json);

        if ($io->option('write-artifact') === true) {
            $directory = $root . '/.waaseyaa';
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Could not create the field-access artifact directory.');
            }
            $target = $directory . '/field-access-preflight.json';
            $temporary = tempnam($directory, '.field-access-preflight.');
            if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $target)) {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
                throw new \RuntimeException('Could not write the field-access preflight artifact.');
            }
        }

        return $result->ready ? 0 : 1;
    }

    /** @return array<string, FieldReadLevel> */
    private function loadClassificationArtifact(string $root): array
    {
        $path = $root . '/.waaseyaa/field-access-classification.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $fields = is_array($decoded) ? ($decoded['fields'] ?? null) : null;
        if (!is_array($fields) || array_is_list($fields)) {
            throw new \RuntimeException('Field-access classification artifact must contain an object-valued "fields" map.');
        }
        $levels = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key) || preg_match('/^[^|]+\|[^|]+\|[^|]+$/', $key) !== 1 || !is_string($value)) {
                throw new \RuntimeException('Field-access classification artifact contains an invalid field entry.');
            }
            $level = FieldReadLevel::tryFrom($value);
            if ($level === null) {
                throw new \RuntimeException(sprintf('Field-access classification artifact has invalid level for "%s".', $key));
            }
            $levels[$key] = $level;
        }
        ksort($levels);

        return $levels;
    }
}
