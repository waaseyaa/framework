<?php

declare(strict_types=1);

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$projectRoot = $argv[1] ?? '';
if ($projectRoot === '') {
    fwrite(STDERR, "usage: prepare-preflight.php <project-root>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

putenv('APP_ENV=local');
$_ENV['APP_ENV'] = 'local';
$kernel = new HttpKernel($projectRoot);
$kernel->bootForCli();
$manager = $kernel->getEntityTypeManager();
$handler = new FieldAccessPreflightHandler(
    new DatabaseFieldAccessInventoryScanner($kernel->getDatabase(), $manager),
    $manager,
    projectRoot: $projectRoot,
);

$definition = new InputDefinition([
    new InputOption('format', null, InputOption::VALUE_REQUIRED, '', 'json'),
    new InputOption('write-artifact', null, InputOption::VALUE_NONE),
]);

$dot = $projectRoot . '/.waaseyaa';
if (!is_dir($dot) && !mkdir($dot, 0o775, true) && !is_dir($dot)) {
    fwrite(STDERR, "could not create .waaseyaa\n");
    exit(1);
}
$classificationPath = $dot . '/field-access-classification.json';
$document = ['fields' => []];
if (is_file($classificationPath)) {
    $loaded = json_decode((string) file_get_contents($classificationPath), true, flags: JSON_THROW_ON_ERROR);
    if (is_array($loaded)) {
        $document = $loaded;
        $document['fields'] ??= [];
    }
}

$scan = static function () use ($handler, $definition): array {
    $output = new BufferedOutput();
    $code = $handler->execute(new SymfonyCommandIO(
        new ArrayInput(['--format' => 'json'], $definition),
        $output,
    ));
    $payload = $output->fetch();
    $start = strpos($payload, '{');
    if ($start === false) {
        throw new RuntimeException('field-access:preflight did not emit JSON: ' . substr($payload, 0, 500));
    }
    $data = json_decode(substr($payload, $start), true, flags: JSON_THROW_ON_ERROR);
    $data['_code'] = $code;
    return $data;
};

$ready = false;
for ($i = 0; $i < 8; $i++) {
    $data = $scan();
    $unclassified = $data['unclassified_entries'] ?? [];
    if (($data['ready'] ?? false) === true && $unclassified === []) {
        $ready = true;
        break;
    }
    if ($unclassified === []) {
        fwrite(STDERR, "preflight not ready and no unclassified entries to classify\n");
        exit(1);
    }
    foreach ($unclassified as $key) {
        if (is_string($key)) {
            $document['fields'][$key] ??= 'public';
        }
    }
    file_put_contents(
        $classificationPath,
        json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
}
if ($ready !== true) {
    fwrite(STDERR, "could not make field-access preflight ready for worker acceptance\n");
    exit(1);
}

$output = new BufferedOutput();
$code = $handler->execute(new SymfonyCommandIO(
    new ArrayInput(['--write-artifact' => true], $definition),
    $output,
));
if ($code !== 0) {
    fwrite(STDERR, "field-access preflight write-artifact failed with {$code}\n");
    exit($code);
}
fwrite(STDOUT, "field-access preflight artifact ready\n");
