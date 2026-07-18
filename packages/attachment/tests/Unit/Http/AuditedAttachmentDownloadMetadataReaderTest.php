<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Http\AuditedAttachmentDownloadMetadataReader;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;

#[CoversClass(AuditedAttachmentDownloadMetadataReader::class)]
final class AuditedAttachmentDownloadMetadataReaderTest extends TestCase
{
    #[Test]
    public function readsOnlyTheFixedMetadataSetAfterDurableReservation(): void
    {
        $events = [];
        $descriptor = null;
        $ledger = new class ($events, $descriptor) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$events, private ?PrivilegedReadDescriptor &$descriptor) {}

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve';
                $this->descriptor = $descriptor;

                return new PrivilegedReadReceipt('attachment-download-read');
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:' . $outcome->value;
            }
        };
        $capabilities = new InMemoryCapabilityRegistry();
        $reader = new AuditedAttachmentDownloadMetadataReader(
            new AuditedFieldRead($capabilities, $ledger),
            $capabilities,
            'classification-1',
            'policy-1',
        );
        $attachment = new Attachment([
            'id' => 9,
            'storage_uri' => 'private://document.pdf',
            'content_type' => 'application/pdf',
            'filename' => 'document.pdf',
            'checksum' => 'must-not-be-read',
        ]);
        $account = new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }
            public function hasPermission(string $permission): bool
            {
                return false;
            }
            public function getRoles(): array
            {
                return ['authenticated'];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };

        $metadata = $reader->read($attachment, $account);

        self::assertSame('private://document.pdf', $metadata->storageUri);
        self::assertSame('application/pdf', $metadata->contentType);
        self::assertSame('document.pdf', $metadata->filename);
        self::assertSame(['reserve', 'finalize:succeeded'], $events);
        self::assertNotNull($descriptor);
        self::assertSame(['storage_uri', 'content_type', 'filename'], $descriptor->fields);
        self::assertSame(42, $descriptor->actorId);
    }
}
