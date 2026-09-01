<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Media\File;
use Waaseyaa\Media\FileRepositoryInterface;
use Waaseyaa\Media\LocalFileRepository;

/**
 * @covers \Waaseyaa\Media\LocalFileRepository
 */
final class LocalFileRepositoryTest extends TestCase
{
    private string $rootDir;
    private LocalFileRepository $repository;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir() . '/waaseyaa_local_files_' . uniqid();
        $this->repository = new LocalFileRepository($this->rootDir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->rootDir)) {
            return;
        }

        (new Filesystem())->remove($this->rootDir);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(FileRepositoryInterface::class, $this->repository);
    }

    public function testSaveAndLoadRoundTripsOriginalName(): void
    {
        // Non-ASCII (Anishinaabemowin) original names must survive the JSON
        // sidecar byte-for-byte while the disk-facing filename stays sanitized.
        $file = new File(
            uri: 'public://Ozhibii_igan_ab12cd34.png',
            filename: 'Ozhibii_igan_ab12cd34.png',
            mimeType: 'image/png',
            originalName: 'Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png',
        );

        $this->repository->save($file);
        $loaded = $this->repository->load('public://Ozhibii_igan_ab12cd34.png');

        $this->assertNotNull($loaded);
        $this->assertSame('Ozhibiiʼigan ᐊᓂᔑᓈᐯᒧᐎᓐ.png', $loaded->originalName);
        $this->assertSame('Ozhibii_igan_ab12cd34.png', $loaded->filename);
    }

    public function testLoadWithoutOriginalNameYieldsNull(): void
    {
        $file = new File(uri: 'public://plain.txt', filename: 'plain.txt');

        $this->repository->save($file);
        $loaded = $this->repository->load('public://plain.txt');

        $this->assertNotNull($loaded);
        $this->assertNull($loaded->originalName);
    }

    public function testConstructorCreatesRootDirectory(): void
    {
        $this->assertDirectoryExists($this->rootDir);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $file = new File(
            uri: 'public://images/2026/photo.jpg',
            filename: 'photo.jpg',
            mimeType: 'image/jpeg',
            size: 1024,
            ownerId: 7,
            createdTime: 1700000000,
        );

        $this->repository->save($file);
        $loaded = $this->repository->load($file->uri);

        $this->assertNotNull($loaded);
        $this->assertSame($file->uri, $loaded->uri);
        $this->assertSame($file->filename, $loaded->filename);
        $this->assertSame($file->mimeType, $loaded->mimeType);
        $this->assertSame($file->size, $loaded->size);
        $this->assertSame($file->ownerId, $loaded->ownerId);
    }

    public function testPersistenceAcrossRepositoryInstances(): void
    {
        $file = new File(uri: 'public://docs/readme.pdf', filename: 'readme.pdf', ownerId: 2);
        $this->repository->save($file);

        $freshRepository = new LocalFileRepository($this->rootDir);
        $loaded = $freshRepository->load($file->uri);

        $this->assertNotNull($loaded);
        $this->assertSame('readme.pdf', $loaded->filename);
        $this->assertSame(2, $loaded->ownerId);
    }

    public function testDeleteExistingFile(): void
    {
        $file = new File(uri: 'public://tmp/to-delete.txt', filename: 'to-delete.txt');
        $this->repository->save($file);

        $deleted = $this->repository->delete($file->uri);

        $this->assertTrue($deleted);
        $this->assertNull($this->repository->load($file->uri));
    }

    public function testDeleteNonExistentReturnsFalse(): void
    {
        $this->assertFalse($this->repository->delete('public://missing.txt'));
    }

    public function testFindByOwnerReturnsMatchingFilesOnly(): void
    {
        $this->repository->save(new File(uri: 'public://a.txt', filename: 'a.txt', ownerId: 1));
        $this->repository->save(new File(uri: 'public://b.txt', filename: 'b.txt', ownerId: 2));
        $this->repository->save(new File(uri: 'public://c.txt', filename: 'c.txt', ownerId: 1));

        $ownerOneFiles = $this->repository->findByOwner(1);

        $this->assertCount(2, $ownerOneFiles);
        $this->assertSame('public://a.txt', $ownerOneFiles[0]->uri);
        $this->assertSame('public://c.txt', $ownerOneFiles[1]->uri);
    }

    public function testFindByOwnerReturnsEmptyWhenNoMatches(): void
    {
        $this->repository->save(new File(uri: 'public://a.txt', filename: 'a.txt', ownerId: 1));

        $result = $this->repository->findByOwner(999);

        $this->assertSame([], $result);
    }

    public function testSaveSanitizesPathTraversalInUri(): void
    {
        $file = new File(uri: 'public://../../etc/passwd', filename: 'passwd');
        $this->repository->save($file);

        $loaded = $this->repository->load($file->uri);
        $this->assertNotNull($loaded);
        $this->assertSame('passwd', $loaded->filename);
    }

    public function testSaveSanitizedPathTraversalStaysConfinedToRoot(): void
    {
        $file = new File(uri: 'public://../../etc/passwd', filename: 'passwd');
        $this->repository->save($file);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            $this->assertStringStartsWith(
                realpath($this->rootDir),
                realpath($fileInfo->getPathname()),
                'Sanitized metadata path must stay confined under the repository root.',
            );
        }
    }

    /**
     * Regression for #2758: distinct URI authorities (the `host` component
     * `parse_url()` splits off before `path`, e.g. `images` vs `docs` in
     * `public://images/shared.pdf` and `public://docs/shared.pdf`) must not
     * be discarded when deriving the sidecar location, or two documented,
     * distinct file identities silently alias onto the same metadata file.
     */
    public function testDistinctAuthoritiesWithSameRelativePathDoNotCollide(): void
    {
        $first = new File(uri: 'public://images/shared.pdf', filename: 'images-shared.pdf', ownerId: 1);
        $second = new File(uri: 'public://docs/shared.pdf', filename: 'docs-shared.pdf', ownerId: 2);

        $this->repository->save($first);
        $this->repository->save($second);

        $loadedFirst = $this->repository->load('public://images/shared.pdf');
        $loadedSecond = $this->repository->load('public://docs/shared.pdf');

        $this->assertNotNull($loadedFirst);
        $this->assertNotNull($loadedSecond);
        $this->assertSame('images-shared.pdf', $loadedFirst->filename);
        $this->assertSame('docs-shared.pdf', $loadedSecond->filename);
        $this->assertSame(1, $loadedFirst->ownerId);
        $this->assertSame(2, $loadedSecond->ownerId);

        // Deleting one must not remove the other's metadata.
        $this->assertTrue($this->repository->delete('public://images/shared.pdf'));
        $this->assertNull($this->repository->load('public://images/shared.pdf'));
        $this->assertNotNull($this->repository->load('public://docs/shared.pdf'));
    }

    public function testUploadProducedRootUriRoundTrips(): void
    {
        // Real uploads mint root-level URIs (`public://<sanitizedName>`),
        // no nested authority segment — the positive control alongside the
        // documented nested-path form covered elsewhere in this class.
        $file = new File(uri: 'public://ab12cd34ef56.pdf', filename: 'ab12cd34ef56.pdf', ownerId: 5);
        $this->repository->save($file);

        $loaded = $this->repository->load('public://ab12cd34ef56.pdf');

        $this->assertNotNull($loaded);
        $this->assertSame('public://ab12cd34ef56.pdf', $loaded->uri);
        $this->assertSame(5, $loaded->ownerId);
    }

    public function testSaveReplacesSidecarViaRenameNotInPlaceTruncation(): void
    {
        // #2758 follow-up: an in-place file_put_contents() truncates and
        // rewrites the same inode, so a concurrent load() opening the
        // sidecar mid-write can observe a partial/corrupt JSON body.
        // Writing to a temp file and rename()-ing it into place instead
        // swaps the directory entry to a *new* inode atomically -- a
        // concurrent reader always sees either the fully old file or the
        // fully new one, never a partial one. Prove the swap actually
        // happens by observing the inode change across an update save(),
        // and that no temp artifact survives.
        $metadataPath = $this->rootDir . '/public/atomic/first.txt.meta.json';

        $this->repository->save(new File(uri: 'public://atomic/first.txt', filename: 'first.txt', ownerId: 1));
        $this->assertFileExists($metadataPath);
        $originalInode = fileinode($metadataPath);
        $this->assertNotFalse($originalInode);

        $this->repository->save(new File(uri: 'public://atomic/first.txt', filename: 'first-renamed.txt', ownerId: 1));
        clearstatcache(true, $metadataPath);
        $updatedInode = fileinode($metadataPath);

        $this->assertNotSame(
            $originalInode,
            $updatedInode,
            'save() must replace the sidecar via rename(), not truncate it in place.',
        );

        $tempArtifacts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getPathname() !== $metadataPath) {
                $tempArtifacts[] = $fileInfo->getPathname();
            }
        }

        $this->assertSame([], $tempArtifacts, 'No temp sidecar artifacts must remain after save().');

        $loaded = $this->repository->load('public://atomic/first.txt');
        $this->assertNotNull($loaded);
        $this->assertSame('first-renamed.txt', $loaded->filename);
    }

    /**
     * Regression for #2758's migration/reconciliation acceptance criterion:
     * a sidecar left behind at the pre-fix collision-prone location (scheme
     * + path only, `host` discarded) must be detected and relocated to the
     * location its own recorded `uri` resolves to under the current layout,
     * so previously-saved metadata does not become silently unreachable
     * after upgrading.
     */
    public function testReconcileLegacySidecarsRelocatesToCurrentLayout(): void
    {
        // Simulate a sidecar written by the pre-#2758 algorithm for
        // `public://images/shared.pdf`: it derived scheme "public" + path
        // "shared.pdf" only, discarding the "images" host/authority segment.
        $legacyPath = $this->rootDir . '/public/shared.pdf.meta.json';
        mkdir(dirname($legacyPath), 0o755, true);
        file_put_contents($legacyPath, json_encode([
            'uri' => 'public://images/shared.pdf',
            'filename' => 'shared.pdf',
            'mimeType' => 'application/pdf',
            'size' => 42,
            'status' => 'permanent',
            'ownerId' => 3,
            'createdTime' => null,
            'originalName' => null,
        ], JSON_THROW_ON_ERROR));

        $this->assertNull($this->repository->load('public://images/shared.pdf'), 'Not yet reachable at the current layout.');

        $report = $this->repository->reconcileLegacySidecars();

        $this->assertCount(1, $report);
        $this->assertSame('relocated', $report[0]['action']);
        $this->assertSame('public://images/shared.pdf', $report[0]['uri']);
        $this->assertFileDoesNotExist($legacyPath);

        $loaded = $this->repository->load('public://images/shared.pdf');
        $this->assertNotNull($loaded);
        $this->assertSame('shared.pdf', $loaded->filename);
        $this->assertSame(3, $loaded->ownerId);
    }

    /**
     * A legacy-location sidecar must never silently overwrite metadata that
     * already lives at the current-layout location for the same URI — the
     * issue's acceptance criterion explicitly forbids picking a winner. The
     * reconciliation report must surface the conflict instead.
     */
    public function testReconcileLegacySidecarsReportsConflictWithoutOverwriting(): void
    {
        $current = new File(uri: 'public://images/shared.pdf', filename: 'current.pdf', ownerId: 9);
        $this->repository->save($current);

        $legacyPath = $this->rootDir . '/public/shared.pdf.meta.json';
        if (!is_dir(dirname($legacyPath))) {
            mkdir(dirname($legacyPath), 0o755, true);
        }
        file_put_contents($legacyPath, json_encode([
            'uri' => 'public://images/shared.pdf',
            'filename' => 'stale.pdf',
            'mimeType' => 'application/pdf',
            'size' => 1,
            'status' => 'permanent',
            'ownerId' => 1,
            'createdTime' => null,
            'originalName' => null,
        ], JSON_THROW_ON_ERROR));

        $report = $this->repository->reconcileLegacySidecars();

        $this->assertCount(1, $report);
        $this->assertSame('conflict', $report[0]['action']);
        $this->assertFileExists($legacyPath, 'Conflicting legacy sidecar must be left in place for operator review.');

        $loaded = $this->repository->load('public://images/shared.pdf');
        $this->assertNotNull($loaded);
        $this->assertSame('current.pdf', $loaded->filename, 'Existing current-layout metadata must not be overwritten.');
    }

    public function testReconcileLegacySidecarsIsIdempotentWhenAlreadyCurrent(): void
    {
        $this->repository->save(new File(uri: 'public://images/shared.pdf', filename: 'shared.pdf', ownerId: 4));

        $report = $this->repository->reconcileLegacySidecars();

        $this->assertSame([], $report);
    }
}
