<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

/** @api */
final class ProjectSourceDiscovery
{
    private const EXCLUDED_SEGMENTS = ['.git', '.waaseyaa', 'node_modules', 'var', 'vendor'];
    private const SHIPPED_EXTENSIONS = ['css', 'html', 'js', 'json', 'md', 'php', 'twig', 'ts', 'yaml', 'yml'];

    public function discover(string $projectRoot): ProjectSourceSnapshot
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Site doctor requires an existing project root.');
        }
        $sources = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                static function (\SplFileInfo $file) use ($root): bool {
                    if ($file->isLink()) {
                        return false;
                    }
                    if ($file->isDir()) {
                        if ($file->getFilename() === 'tests' && rtrim(str_replace('\\', '/', $file->getPath()), '/') === str_replace('\\', '/', $root)) {
                            return false;
                        }
                        return !in_array($file->getFilename(), self::EXCLUDED_SEGMENTS, true);
                    }

                    return true;
                },
            ),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink() || !in_array(strtolower($file->getExtension()), self::SHIPPED_EXTENSIONS, true)) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            $bytes = file_get_contents($absolute);
            if ($bytes === false) {
                throw new \RuntimeException("Unable to read architecture source: {$relative}");
            }
            $sources[$relative] = $bytes;
        }
        ksort($sources, SORT_STRING);
        $identity = '';
        foreach ($sources as $path => $bytes) {
            $identity .= $path . "\0" . hash('sha256', $bytes) . "\n";
        }

        return new ProjectSourceSnapshot($sources, hash('sha256', $identity));
    }
}
