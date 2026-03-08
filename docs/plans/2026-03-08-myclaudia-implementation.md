# MyClaudia Phase 1 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build MyClaudia Phase 1 — Gmail ingestion → Event/Person/Commitment entities → Day Brief v0 (web UI + CLI read-only).

**Architecture:** Day Brief-first. Scaffold a Waaseyaa app (`~/dev/myclaudia`), define the six core entities, wire Gmail ingestion via the Waaseyaa Ingestion pipeline, run AI commitment extraction as a pipeline step, and render a Day Brief from committed memory. The CLI reads the same data via console commands.

**Tech Stack:** PHP 8.3+, Symfony 7, Waaseyaa framework (entity, entity-storage, foundation, ai-pipeline, api, routing, cli), Google Gmail API (via HTTP), PHPUnit 10.5, `PdoDatabase::createSqlite()` for integration tests.

---

## Prerequisites

- Waaseyaa monorepo at `~/dev/waaseyaa` (working)
- PHP 8.3+, Composer, Symfony CLI available
- `~/dev/myclaudia/` does not yet exist
- A Google Cloud project with Gmail API enabled and `gcp-oauth.keys.json` available

---

## Task 1: Scaffold the MyClaudia app

**Files:**
- Create: `~/dev/myclaudia/` (from skeleton)

**Step 1: Copy the Waaseyaa skeleton**

```bash
cp -r ~/dev/waaseyaa/skeleton ~/dev/myclaudia
cd ~/dev/myclaudia
```

**Step 2: Update composer.json name and add path repos for local waaseyaa packages**

Edit `~/dev/myclaudia/composer.json`:

```json
{
    "name": "jonesrussell/myclaudia",
    "description": "MyClaudia — AI personal operations system",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "repositories": [
        { "type": "path", "url": "~/dev/waaseyaa/packages/*" }
    ],
    "require": {
        "php": ">=8.3",
        "waaseyaa/foundation": "@dev",
        "waaseyaa/entity": "@dev",
        "waaseyaa/entity-storage": "@dev",
        "waaseyaa/access": "@dev",
        "waaseyaa/user": "@dev",
        "waaseyaa/config": "@dev",
        "waaseyaa/api": "@dev",
        "waaseyaa/routing": "@dev",
        "waaseyaa/cli": "@dev",
        "waaseyaa/database-legacy": "@dev",
        "waaseyaa/cache": "@dev",
        "waaseyaa/field": "@dev",
        "waaseyaa/ai-pipeline": "@dev",
        "waaseyaa/ai-agent": "@dev",
        "waaseyaa/validation": "@dev",
        "google/apiclient": "^2.15"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "waaseyaa/testing": "@dev"
    },
    "autoload": {
        "psr-4": { "MyClaudia\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "MyClaudia\\Tests\\": "tests/" }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

**Step 3: Install dependencies**

```bash
composer install
```

Expected: Vendor directory created, all waaseyaa/* packages symlinked from the monorepo.

**Step 4: Initialise git**

```bash
git init
echo "vendor/" > .gitignore
echo ".env" >> .gitignore
git add .
git commit -m "chore: scaffold myclaudia from waaseyaa skeleton"
```

---

## Task 2: Define the Account entity

**Files:**
- Create: `src/Entity/Account.php`
- Create: `src/Entity/AccountAccessPolicy.php`
- Create: `src/ServiceProvider.php`
- Create: `tests/Unit/Entity/AccountTest.php`

**Step 1: Write the failing test**

```php
// tests/Unit/Entity/AccountTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Entity;

use MyClaudia\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('account', $account->getEntityTypeId());
    }

    public function testGetEmail(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('test@example.com', $account->get('email'));
    }
}
```

**Step 2: Run test to confirm it fails**

```bash
cd ~/dev/myclaudia
./vendor/bin/phpunit tests/Unit/Entity/AccountTest.php -v
```

Expected: FAIL — class not found.

**Step 3: Implement Account entity**

```php
// src/Entity/Account.php
<?php

declare(strict_types=1);

namespace MyClaudia\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Account extends ContentEntityBase
{
    protected string $entityTypeId = 'account';

    protected array $entityKeys = [
        'id'    => 'aid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'account', $this->entityKeys);
    }
}
```

**Step 4: Run test to confirm it passes**

```bash
./vendor/bin/phpunit tests/Unit/Entity/AccountTest.php -v
```

Expected: PASS.

**Step 5: Create ServiceProvider stub**

```php
// src/ServiceProvider.php
<?php

declare(strict_types=1);

namespace MyClaudia;

use MyClaudia\Entity\Account;
use Waaseyaa\Foundation\ServiceProvider\AbstractServiceProvider;
use Waaseyaa\Entity\EntityType;

final class ServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            entityClass: Account::class,
            entityKeys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }
}
```

**Step 6: Commit**

```bash
git add src/ tests/
git commit -m "feat: add Account entity"
```

---

## Task 3: Define Event, Person, and Integration entities

**Files:**
- Create: `src/Entity/McEvent.php` (named McEvent to avoid PHP reserved word collision)
- Create: `src/Entity/Person.php`
- Create: `src/Entity/Integration.php`
- Modify: `src/ServiceProvider.php`
- Create: `tests/Unit/Entity/McEventTest.php`
- Create: `tests/Unit/Entity/PersonTest.php`

**Step 1: Write failing tests**

```php
// tests/Unit/Entity/McEventTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Entity;

use MyClaudia\Entity\McEvent;
use PHPUnit\Framework\TestCase;

final class McEventTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $event = new McEvent([
            'source'  => 'gmail',
            'type'    => 'message.received',
            'payload' => '{}',
        ]);
        self::assertSame('mc_event', $event->getEntityTypeId());
    }

    public function testSourceAndType(): void
    {
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        self::assertSame('gmail', $event->get('source'));
        self::assertSame('message.received', $event->get('type'));
    }
}
```

```php
// tests/Unit/Entity/PersonTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Entity;

use MyClaudia\Entity\Person;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $person = new Person(['email' => 'jane@example.com', 'name' => 'Jane']);
        self::assertSame('person', $person->getEntityTypeId());
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Entity/ -v
```

Expected: FAIL — classes not found.

**Step 3: Implement entities**

```php
// src/Entity/McEvent.php
<?php

declare(strict_types=1);

namespace MyClaudia\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Immutable ingested fact.
 * Named McEvent to avoid collision with PHP's reserved 'Event' keyword context.
 */
final class McEvent extends ContentEntityBase
{
    protected string $entityTypeId = 'mc_event';

    protected array $entityKeys = [
        'id'   => 'eid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'mc_event', $this->entityKeys);
    }
}
```

```php
// src/Entity/Person.php
<?php

declare(strict_types=1);

namespace MyClaudia\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Person extends ContentEntityBase
{
    protected string $entityTypeId = 'person';

    protected array $entityKeys = [
        'id'    => 'pid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'person', $this->entityKeys);
    }
}
```

```php
// src/Entity/Integration.php
<?php

declare(strict_types=1);

namespace MyClaudia\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Integration extends ContentEntityBase
{
    protected string $entityTypeId = 'integration';

    protected array $entityKeys = [
        'id'    => 'iid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'integration', $this->entityKeys);
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Entity/ -v
```

Expected: All PASS.

**Step 5: Register new entity types in ServiceProvider** — add three `registerEntityType()` calls following the same pattern as Account.

**Step 6: Commit**

```bash
git add src/ tests/
git commit -m "feat: add McEvent, Person, Integration entities"
```

---

## Task 4: Define the Commitment entity

**Files:**
- Create: `src/Entity/Commitment.php`
- Create: `tests/Unit/Entity/CommitmentTest.php`
- Modify: `src/ServiceProvider.php`

**Step 1: Write failing test**

```php
// tests/Unit/Entity/CommitmentTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Entity;

use MyClaudia\Entity\Commitment;
use PHPUnit\Framework\TestCase;

final class CommitmentTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $c = new Commitment(['title' => 'Send report', 'status' => 'pending', 'confidence' => 0.9]);
        self::assertSame('commitment', $c->getEntityTypeId());
    }

    public function testDefaultStatus(): void
    {
        $c = new Commitment(['title' => 'Follow up']);
        self::assertSame('pending', $c->get('status'));
    }

    public function testConfidence(): void
    {
        $c = new Commitment(['title' => 'Review PR', 'confidence' => 0.75]);
        self::assertSame(0.75, $c->get('confidence'));
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Entity/CommitmentTest.php -v
```

Expected: FAIL.

**Step 3: Implement Commitment entity**

```php
// src/Entity/Commitment.php
<?php

declare(strict_types=1);

namespace MyClaudia\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Commitment extends ContentEntityBase
{
    protected string $entityTypeId = 'commitment';

    protected array $entityKeys = [
        'id'    => 'cid',
        'uuid'  => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        if (!array_key_exists('status', $values)) {
            $values['status'] = 'pending';
        }
        if (!array_key_exists('confidence', $values)) {
            $values['confidence'] = 1.0;
        }
        parent::__construct($values, 'commitment', $this->entityKeys);
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Entity/ -v
```

Expected: All PASS.

**Step 5: Register in ServiceProvider, commit**

```bash
git add src/ tests/
git commit -m "feat: add Commitment entity"
```

---

## Task 5: Gmail ingestion adapter

**Files:**
- Create: `src/Ingestion/GmailIngestor.php`
- Create: `src/Ingestion/GmailMessageNormalizer.php`
- Create: `tests/Unit/Ingestion/GmailMessageNormalizerTest.php`

The Gmail ingestor fetches messages from the Gmail API and emits `Envelope` objects into the Waaseyaa ingestion pipeline. Normalisation is the testable part; the HTTP call is not.

**Step 1: Write failing test for the normalizer**

```php
// tests/Unit/Ingestion/GmailMessageNormalizerTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Ingestion;

use MyClaudia\Ingestion\GmailMessageNormalizer;
use PHPUnit\Framework\TestCase;

final class GmailMessageNormalizerTest extends TestCase
{
    public function testNormalizesGmailMessage(): void
    {
        $raw = [
            'id'       => 'msg123',
            'threadId' => 'thread456',
            'payload'  => [
                'headers' => [
                    ['name' => 'From',    'value' => 'Jane <jane@example.com>'],
                    ['name' => 'Subject', 'value' => 'Quick question'],
                    ['name' => 'Date',    'value' => 'Sun, 08 Mar 2026 09:00:00 +0000'],
                ],
                'body' => ['data' => base64_encode("Can you send the report by Friday?")],
            ],
        ];

        $normalizer = new GmailMessageNormalizer();
        $envelope   = $normalizer->normalize($raw, tenantId: 'user-1');

        self::assertSame('gmail', $envelope->source);
        self::assertSame('message.received', $envelope->type);
        self::assertSame('msg123', $envelope->payload['message_id']);
        self::assertSame('jane@example.com', $envelope->payload['from_email']);
        self::assertSame('Quick question', $envelope->payload['subject']);
        self::assertSame('Can you send the report by Friday?', $envelope->payload['body']);
        self::assertSame('user-1', $envelope->tenantId);
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Ingestion/ -v
```

Expected: FAIL.

**Step 3: Implement GmailMessageNormalizer**

```php
// src/Ingestion/GmailMessageNormalizer.php
<?php

declare(strict_types=1);

namespace MyClaudia\Ingestion;

use Waaseyaa\Foundation\Ingestion\Envelope;

final class GmailMessageNormalizer
{
    public function normalize(array $raw, string $tenantId): Envelope
    {
        $headers = [];
        foreach ($raw['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }

        $fromRaw   = $headers['from'] ?? '';
        $fromEmail = $this->extractEmail($fromRaw);
        $body      = base64_decode(strtr($raw['payload']['body']['data'] ?? '', '-_', '+/'));

        return new Envelope(
            source:    'gmail',
            type:      'message.received',
            payload:   [
                'message_id' => $raw['id'],
                'thread_id'  => $raw['threadId'],
                'from_email' => $fromEmail,
                'from_name'  => $this->extractName($fromRaw),
                'subject'    => $headers['subject'] ?? '(no subject)',
                'date'       => $headers['date'] ?? '',
                'body'       => $body,
            ],
            timestamp: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            traceId:   uniqid('gmail-', true),
            tenantId:  $tenantId,
        );
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return $m[1];
        }
        return trim($from);
    }

    private function extractName(string $from): string
    {
        if (preg_match('/^(.+?)\s*</', $from, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Ingestion/ -v
```

Expected: PASS.

**Step 5: Commit**

```bash
git add src/Ingestion/ tests/Unit/Ingestion/
git commit -m "feat: Gmail message normalizer"
```

---

## Task 6: Event and Person creation from Envelope

**Files:**
- Create: `src/Ingestion/EventHandler.php`
- Create: `tests/Unit/Ingestion/EventHandlerTest.php`

This handler receives a normalised `Envelope`, persists an `McEvent`, and upserts a `Person` for the sender.

**Step 1: Write failing integration test**

```php
// tests/Unit/Ingestion/EventHandlerTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Ingestion;

use MyClaudia\Entity\McEvent;
use MyClaudia\Entity\Person;
use MyClaudia\Ingestion\EventHandler;
use Waaseyaa\Foundation\Ingestion\Envelope;
use Waaseyaa\EntityStorage\InMemoryEntityStorage;
use PHPUnit\Framework\TestCase;

final class EventHandlerTest extends TestCase
{
    public function testCreatesEventAndPersonFromEnvelope(): void
    {
        $eventStorage  = new InMemoryEntityStorage();
        $personStorage = new InMemoryEntityStorage();

        $handler = new EventHandler($eventStorage, $personStorage);

        $envelope = new Envelope(
            source:    'gmail',
            type:      'message.received',
            payload:   [
                'message_id' => 'msg1',
                'thread_id'  => 'thread1',
                'from_email' => 'jane@example.com',
                'from_name'  => 'Jane',
                'subject'    => 'Ping',
                'body'       => 'Can you review this?',
                'date'       => '2026-03-08T09:00:00+00:00',
            ],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId:   'trace-1',
            tenantId:  'user-1',
        );

        $handler->handle($envelope);

        $events  = $eventStorage->loadMultiple('mc_event');
        $persons = $personStorage->loadMultiple('person');

        self::assertCount(1, $events);
        self::assertCount(1, $persons);
        self::assertSame('gmail', $events[0]->get('source'));
        self::assertSame('jane@example.com', $persons[0]->get('email'));
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Ingestion/EventHandlerTest.php -v
```

Expected: FAIL.

**Step 3: Implement EventHandler**

```php
// src/Ingestion/EventHandler.php
<?php

declare(strict_types=1);

namespace MyClaudia\Ingestion;

use MyClaudia\Entity\McEvent;
use MyClaudia\Entity\Person;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Ingestion\Envelope;

final class EventHandler
{
    public function __construct(
        private readonly EntityStorageInterface $eventStorage,
        private readonly EntityStorageInterface $personStorage,
    ) {}

    public function handle(Envelope $envelope): McEvent
    {
        $event = new McEvent([
            'source'    => $envelope->source,
            'type'      => $envelope->type,
            'payload'   => json_encode($envelope->payload),
            'tenant_id' => $envelope->tenantId,
            'trace_id'  => $envelope->traceId,
            'occurred'  => $envelope->timestamp,
        ]);
        $this->eventStorage->save($event);

        $this->upsertPerson(
            email:    $envelope->payload['from_email'],
            name:     $envelope->payload['from_name'] ?? '',
            tenantId: $envelope->tenantId ?? '',
        );

        return $event;
    }

    private function upsertPerson(string $email, string $name, string $tenantId): void
    {
        $existing = $this->personStorage->loadByProperties('person', ['email' => $email]);
        if (!empty($existing)) {
            return;
        }

        $person = new Person([
            'email'     => $email,
            'name'      => $name ?: $email,
            'tenant_id' => $tenantId,
        ]);
        $this->personStorage->save($person);
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Ingestion/ -v
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add src/Ingestion/EventHandler.php tests/Unit/Ingestion/EventHandlerTest.php
git commit -m "feat: event handler creates McEvent and upserts Person"
```

---

## Task 7: Commitment extraction pipeline step

**Files:**
- Create: `src/Pipeline/CommitmentExtractionStep.php`
- Create: `tests/Unit/Pipeline/CommitmentExtractionStepTest.php`

This is a `PipelineStepInterface` that takes a message body and returns extracted commitment candidates with confidence scores. The Claude API call is injected so tests can use a stub.

**Step 1: Write failing test with stub AI client**

```php
// tests/Unit/Pipeline/CommitmentExtractionStepTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Pipeline;

use MyClaudia\Pipeline\CommitmentExtractionStep;
use Waaseyaa\AI\Pipeline\PipelineContext;
use PHPUnit\Framework\TestCase;

final class CommitmentExtractionStepTest extends TestCase
{
    public function testExtractsCommitmentsFromBody(): void
    {
        // Stub AI client returns a fixed JSON response
        $aiClient = new class {
            public function complete(string $prompt): string
            {
                return json_encode([
                    ['title' => 'Send report by Friday', 'confidence' => 0.92],
                ]);
            }
        };

        $step    = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', accountId: 'user-1');
        $result  = $step->process(
            ['body' => 'Can you send the report by Friday?', 'from_email' => 'jane@example.com'],
            $context
        );

        self::assertTrue($result->success);
        self::assertCount(1, $result->output['commitments']);
        self::assertSame('Send report by Friday', $result->output['commitments'][0]['title']);
        self::assertSame(0.92, $result->output['commitments'][0]['confidence']);
    }

    public function testReturnsEmptyForNonCommitmentBody(): void
    {
        $aiClient = new class {
            public function complete(string $prompt): string
            {
                return json_encode([]);
            }
        };

        $step    = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', accountId: 'user-1');
        $result  = $step->process(['body' => 'Just saying hi!', 'from_email' => 'jane@example.com'], $context);

        self::assertTrue($result->success);
        self::assertCount(0, $result->output['commitments']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Pipeline/ -v
```

Expected: FAIL.

**Step 3: Implement CommitmentExtractionStep**

```php
// src/Pipeline/CommitmentExtractionStep.php
<?php

declare(strict_types=1);

namespace MyClaudia\Pipeline;

use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

final class CommitmentExtractionStep implements PipelineStepInterface
{
    public function __construct(private readonly object $aiClient) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $body      = $input['body'] ?? '';
        $fromEmail = $input['from_email'] ?? 'unknown';

        $prompt = <<<PROMPT
        You are an AI assistant extracting commitments from emails.

        Email body:
        "{$body}"

        Sender: {$fromEmail}

        Return a JSON array of commitments. Each item: {"title": "...", "confidence": 0.0-1.0}.
        Confidence > 0.7 means you are confident this is a real commitment.
        Return [] if no commitments found.
        Return only valid JSON, no commentary.
        PROMPT;

        $raw         = $this->aiClient->complete($prompt);
        $commitments = json_decode($raw, true) ?? [];

        return StepResult::success(['commitments' => $commitments]);
    }

    public function describe(): string
    {
        return 'Extract commitment candidates from a message body using AI.';
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit tests/Unit/Pipeline/ -v
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add src/Pipeline/ tests/Unit/Pipeline/
git commit -m "feat: commitment extraction pipeline step"
```

---

## Task 8: Commitment persistence from extraction results

**Files:**
- Create: `src/Ingestion/CommitmentHandler.php`
- Create: `tests/Unit/Ingestion/CommitmentHandlerTest.php`

Takes the extraction results, filters by confidence threshold (>= 0.7), and persists `Commitment` entities linked to the source event.

**Step 1: Write failing test**

```php
// tests/Unit/Ingestion/CommitmentHandlerTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Ingestion;

use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use MyClaudia\Ingestion\CommitmentHandler;
use Waaseyaa\EntityStorage\InMemoryEntityStorage;
use PHPUnit\Framework\TestCase;

final class CommitmentHandlerTest extends TestCase
{
    public function testPersistsHighConfidenceCommitments(): void
    {
        $storage = new InMemoryEntityStorage();
        $handler = new CommitmentHandler($storage);

        $event       = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        $candidates  = [
            ['title' => 'Send report', 'confidence' => 0.92],
            ['title' => 'Maybe attend', 'confidence' => 0.4],  // below threshold
        ];

        $handler->handle($candidates, $event, personId: 'person-1', tenantId: 'user-1');

        $commitments = $storage->loadMultiple('commitment');
        self::assertCount(1, $commitments);
        self::assertSame('Send report', $commitments[0]->get('title'));
        self::assertSame(0.92, $commitments[0]->get('confidence'));
        self::assertSame('pending', $commitments[0]->get('status'));
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/Ingestion/CommitmentHandlerTest.php -v
```

Expected: FAIL.

**Step 3: Implement CommitmentHandler**

```php
// src/Ingestion/CommitmentHandler.php
<?php

declare(strict_types=1);

namespace MyClaudia\Ingestion;

use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class CommitmentHandler
{
    private const CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(private readonly EntityStorageInterface $storage) {}

    /** @param array<int, array{title: string, confidence: float}> $candidates */
    public function handle(array $candidates, McEvent $event, string $personId, string $tenantId): void
    {
        foreach ($candidates as $candidate) {
            if (($candidate['confidence'] ?? 0.0) < self::CONFIDENCE_THRESHOLD) {
                continue;
            }

            $commitment = new Commitment([
                'title'           => $candidate['title'],
                'confidence'      => $candidate['confidence'],
                'status'          => 'pending',
                'source_event_id' => $event->id(),
                'person_id'       => $personId,
                'tenant_id'       => $tenantId,
            ]);
            $this->storage->save($commitment);
        }
    }
}
```

**Step 4: Run tests**

```bash
./vendor/bin/phpunit -v
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add src/Ingestion/CommitmentHandler.php tests/Unit/Ingestion/CommitmentHandlerTest.php
git commit -m "feat: commitment handler persists high-confidence candidates"
```

---

## Task 9: Drift detection

**Files:**
- Create: `src/DriftDetector.php`
- Create: `tests/Unit/DriftDetectorTest.php`

Identifies active Commitments with no activity for more than 48 hours. Returns them for surfacing in the Day Brief.

**Step 1: Write failing test**

```php
// tests/Unit/DriftDetectorTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit;

use MyClaudia\DriftDetector;
use MyClaudia\Entity\Commitment;
use Waaseyaa\EntityStorage\InMemoryEntityStorage;
use PHPUnit\Framework\TestCase;

final class DriftDetectorTest extends TestCase
{
    public function testDetectsCommitmentsWithNoRecentActivity(): void
    {
        $storage = new InMemoryEntityStorage();

        $stale = new Commitment([
            'title'      => 'Old follow-up',
            'status'     => 'active',
            'updated_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
        ]);
        $storage->save($stale);

        $fresh = new Commitment([
            'title'      => 'Recent task',
            'status'     => 'active',
            'updated_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
        ]);
        $storage->save($fresh);

        $detector = new DriftDetector($storage);
        $drifting = $detector->findDrifting(tenantId: 'user-1');

        self::assertCount(1, $drifting);
        self::assertSame('Old follow-up', $drifting[0]->get('title'));
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/DriftDetectorTest.php -v
```

Expected: FAIL.

**Step 3: Implement DriftDetector**

```php
// src/DriftDetector.php
<?php

declare(strict_types=1);

namespace MyClaudia;

use MyClaudia\Entity\Commitment;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class DriftDetector
{
    private const DRIFT_HOURS = 48;

    public function __construct(private readonly EntityStorageInterface $storage) {}

    /** @return Commitment[] */
    public function findDrifting(string $tenantId): array
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', self::DRIFT_HOURS));
        $active = $this->storage->loadByProperties('commitment', [
            'status'    => 'active',
            'tenant_id' => $tenantId,
        ]);

        return array_values(array_filter(
            $active,
            fn (Commitment $c) => new \DateTimeImmutable($c->get('updated_at') ?? 'now') < $cutoff,
        ));
    }
}
```

**Step 4: Run all tests**

```bash
./vendor/bin/phpunit -v
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add src/DriftDetector.php tests/Unit/DriftDetectorTest.php
git commit -m "feat: drift detection for stale active commitments"
```

---

## Task 10: Day Brief query and assembly

**Files:**
- Create: `src/DayBrief/DayBriefQuery.php`
- Create: `src/DayBrief/DayBriefAssembler.php`
- Create: `tests/Unit/DayBrief/DayBriefAssemblerTest.php`

Assembles the structured data for the Day Brief from entity storage. No AI synthesis here — that comes from the web/CLI layer.

**Step 1: Write failing test**

```php
// tests/Unit/DayBrief/DayBriefAssemblerTest.php
<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\DayBrief;

use MyClaudia\DayBrief\DayBriefAssembler;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use MyClaudia\Entity\Person;
use Waaseyaa\EntityStorage\InMemoryEntityStorage;
use PHPUnit\Framework\TestCase;

final class DayBriefAssemblerTest extends TestCase
{
    public function testAssemblesBriefFromEntities(): void
    {
        $eventStorage      = new InMemoryEntityStorage();
        $commitmentStorage = new InMemoryEntityStorage();
        $personStorage     = new InMemoryEntityStorage();

        // Seed a recent event
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}', 'occurred' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s')]);
        $eventStorage->save($event);

        // Seed a pending commitment
        $commitment = new Commitment(['title' => 'Reply to Jane', 'status' => 'pending', 'confidence' => 0.85]);
        $commitmentStorage->save($commitment);

        $assembler = new DayBriefAssembler($eventStorage, $commitmentStorage, $personStorage);
        $brief     = $assembler->assemble(tenantId: 'user-1', since: new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['recent_events']);
        self::assertCount(1, $brief['pending_commitments']);
        self::assertIsArray($brief['drifting_commitments']);
    }
}
```

**Step 2: Run to confirm failure**

```bash
./vendor/bin/phpunit tests/Unit/DayBrief/ -v
```

Expected: FAIL.

**Step 3: Implement DayBriefAssembler**

```php
// src/DayBrief/DayBriefAssembler.php
<?php

declare(strict_types=1);

namespace MyClaudia\DayBrief;

use MyClaudia\DriftDetector;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class DayBriefAssembler
{
    public function __construct(
        private readonly EntityStorageInterface $eventStorage,
        private readonly EntityStorageInterface $commitmentStorage,
        private readonly EntityStorageInterface $personStorage,
    ) {}

    /** @return array{recent_events: array, pending_commitments: array, drifting_commitments: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since): array
    {
        $recentEvents = array_filter(
            $this->eventStorage->loadByProperties('mc_event', ['tenant_id' => $tenantId]),
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        );

        $pendingCommitments = $this->commitmentStorage->loadByProperties('commitment', [
            'status'    => 'pending',
            'tenant_id' => $tenantId,
        ]);

        $detector         = new DriftDetector($this->commitmentStorage);
        $driftingCommitments = $detector->findDrifting($tenantId);

        return [
            'recent_events'        => array_values($recentEvents),
            'pending_commitments'  => $pendingCommitments,
            'drifting_commitments' => $driftingCommitments,
        ];
    }
}
```

**Step 4: Run all tests**

```bash
./vendor/bin/phpunit -v
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add src/DayBrief/ tests/Unit/DayBrief/
git commit -m "feat: day brief assembler"
```

---

## Task 11: CLI commands — `myclaudia brief` and `myclaudia commitments`

**Files:**
- Create: `src/Command/BriefCommand.php`
- Create: `src/Command/CommitmentsCommand.php`
- Modify: `src/ServiceProvider.php` (register commands)

**Step 1: Implement BriefCommand**

No test for command output format — keep integration tests at the assembler level. This is a thin adapter.

```php
// src/Command/BriefCommand.php
<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use MyClaudia\DayBrief\DayBriefAssembler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:brief', description: 'Show your Day Brief')]
final class BriefCommand extends Command
{
    public function __construct(private readonly DayBriefAssembler $assembler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $brief = $this->assembler->assemble(
            tenantId: 'default',
            since:    new \DateTimeImmutable('-24 hours'),
        );

        $output->writeln('<info>Day Brief</info>');
        $output->writeln('');

        $output->writeln(sprintf('<comment>Recent events (%d)</comment>', count($brief['recent_events'])));
        foreach ($brief['recent_events'] as $event) {
            $output->writeln(sprintf('  [%s] %s', $event->get('source'), $event->get('type')));
        }

        $output->writeln('');
        $output->writeln(sprintf('<comment>Pending commitments (%d)</comment>', count($brief['pending_commitments'])));
        foreach ($brief['pending_commitments'] as $c) {
            $output->writeln(sprintf('  • %s (%.0f%% confidence)', $c->get('title'), $c->get('confidence') * 100));
        }

        if (!empty($brief['drifting_commitments'])) {
            $output->writeln('');
            $output->writeln('<error>Drifting (no activity 48h+)</error>');
            foreach ($brief['drifting_commitments'] as $c) {
                $output->writeln(sprintf('  ! %s', $c->get('title')));
            }
        }

        return Command::SUCCESS;
    }
}
```

```php
// src/Command/CommitmentsCommand.php
<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:commitments', description: 'List active commitments')]
final class CommitmentsCommand extends Command
{
    public function __construct(private readonly EntityStorageInterface $commitmentStorage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commitments = $this->commitmentStorage->loadByProperties('commitment', ['status' => 'active']);

        if (empty($commitments)) {
            $output->writeln('No active commitments.');
            return Command::SUCCESS;
        }

        foreach ($commitments as $c) {
            $output->writeln(sprintf('[%s] %s', strtoupper($c->get('status')), $c->get('title')));
        }

        return Command::SUCCESS;
    }
}
```

**Step 2: Register commands in ServiceProvider — add `registerCommand()` calls**

**Step 3: Smoke test via CLI**

```bash
./bin/waaseyaa myclaudia:brief
./bin/waaseyaa myclaudia:commitments
```

Expected: Commands run without errors (empty output is fine at this stage).

**Step 4: Commit**

```bash
git add src/Command/
git commit -m "feat: CLI brief and commitments commands"
```

---

## Task 12: Minimal web Day Brief view

**Files:**
- Create: `src/Controller/DayBriefController.php`
- Create: `templates/day-brief.html.twig`
- Modify: `config/routes.yaml` (add route)

**Step 1: Implement controller**

```php
// src/Controller/DayBriefController.php
<?php

declare(strict_types=1);

namespace MyClaudia\Controller;

use MyClaudia\DayBrief\DayBriefAssembler;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class DayBriefController
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
        private readonly Environment $twig,
    ) {}

    public function __invoke(): Response
    {
        $brief = $this->assembler->assemble(
            tenantId: 'default',
            since:    new \DateTimeImmutable('-24 hours'),
        );

        return new Response($this->twig->render('day-brief.html.twig', ['brief' => $brief]));
    }
}
```

**Step 2: Create template**

```twig
{# templates/day-brief.html.twig #}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Day Brief — MyClaudia</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; color: #1a1a1a; }
        h1 { font-size: 1.5rem; font-weight: 600; }
        section { margin-top: 2rem; }
        h2 { font-size: 1rem; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: .05em; }
        ul { padding-left: 1.2rem; }
        li { margin: .4rem 0; }
        .drift { color: #c0392b; }
    </style>
</head>
<body>
    <h1>Day Brief</h1>

    <section>
        <h2>Recent Events ({{ brief.recent_events|length }})</h2>
        {% if brief.recent_events is empty %}
            <p>No new events.</p>
        {% else %}
            <ul>
            {% for event in brief.recent_events %}
                <li>[{{ event.get('source') }}] {{ event.get('type') }}</li>
            {% endfor %}
            </ul>
        {% endif %}
    </section>

    <section>
        <h2>Pending Commitments ({{ brief.pending_commitments|length }})</h2>
        {% if brief.pending_commitments is empty %}
            <p>None.</p>
        {% else %}
            <ul>
            {% for c in brief.pending_commitments %}
                <li>{{ c.get('title') }} <small>({{ (c.get('confidence') * 100)|round }}%)</small></li>
            {% endfor %}
            </ul>
        {% endif %}
    </section>

    {% if brief.drifting_commitments is not empty %}
    <section>
        <h2 class="drift">Drifting — No Activity 48h+</h2>
        <ul>
        {% for c in brief.drifting_commitments %}
            <li class="drift">{{ c.get('title') }}</li>
        {% endfor %}
        </ul>
    </section>
    {% endif %}
</body>
</html>
```

**Step 3: Add route to `config/routes.yaml`**

```yaml
myclaudia_brief:
    path: /brief
    controller: MyClaudia\Controller\DayBriefController
    options:
        _public: true
```

**Step 4: Start dev server and verify**

```bash
symfony server:start
# Visit http://localhost:8000/brief
```

Expected: Day Brief page renders without errors.

**Step 5: Commit**

```bash
git add src/Controller/ templates/ config/
git commit -m "feat: web Day Brief view"
```

---

## Task 13: Wire up full Gmail sync (manual trigger)

**Files:**
- Create: `src/Command/GmailSyncCommand.php`
- Modify: `src/ServiceProvider.php`

This command authenticates with Gmail (using the stored OAuth token), fetches the last N messages, normalises and stores them as events, extracts commitments, and outputs a summary.

**Step 1: Implement GmailSyncCommand**

```php
// src/Command/GmailSyncCommand.php
<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use MyClaudia\Ingestion\CommitmentHandler;
use MyClaudia\Ingestion\EventHandler;
use MyClaudia\Ingestion\GmailMessageNormalizer;
use MyClaudia\Pipeline\CommitmentExtractionStep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\AI\Pipeline\PipelineContext;

#[AsCommand(name: 'myclaudia:gmail:sync', description: 'Sync latest Gmail messages')]
final class GmailSyncCommand extends Command
{
    public function __construct(
        private readonly \Google\Client $googleClient,
        private readonly EventHandler $eventHandler,
        private readonly CommitmentHandler $commitmentHandler,
        private readonly CommitmentExtractionStep $extractionStep,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $gmail    = new \Google\Service\Gmail($this->googleClient);
        $messages = $gmail->users_messages->listUsersMessages('me', ['maxResults' => 20])->getMessages();

        if (empty($messages)) {
            $output->writeln('No new messages.');
            return Command::SUCCESS;
        }

        $normalizer = new GmailMessageNormalizer();
        $context    = new PipelineContext(pipelineId: 'gmail-sync', accountId: 'default');

        foreach ($messages as $msg) {
            $raw      = $gmail->users_messages->get('me', $msg->getId(), ['format' => 'full']);
            $envelope = $normalizer->normalize((array) $raw, tenantId: 'default');
            $event    = $this->eventHandler->handle($envelope);

            $result = $this->extractionStep->process(
                ['body' => $envelope->payload['body'], 'from_email' => $envelope->payload['from_email']],
                $context,
            );

            $this->commitmentHandler->handle(
                $result->output['commitments'] ?? [],
                $event,
                personId: 'default',
                tenantId: 'default',
            );

            $output->writeln(sprintf('  Processed: %s', $envelope->payload['subject']));
        }

        $output->writeln('Sync complete.');
        return Command::SUCCESS;
    }
}
```

**Step 2: Set up `.env` with Google credentials path**

```ini
# .env
GOOGLE_APPLICATION_CREDENTIALS=/path/to/gcp-oauth.keys.json
ANTHROPIC_API_KEY=your-key-here
```

**Step 3: Run sync**

```bash
./bin/waaseyaa myclaudia:gmail:sync
```

Expected: Messages processed, commitment candidates extracted, output logged per message.

**Step 4: Run brief to see results**

```bash
./bin/waaseyaa myclaudia:brief
# or visit http://localhost:8000/brief
```

Expected: Real events and extracted commitments in the Day Brief.

**Step 5: Commit**

```bash
git add src/Command/GmailSyncCommand.php .env.example
git commit -m "feat: Gmail sync command wires full ingestion pipeline"
```

---

## Phase 1 Complete

At this point you have:

- ✅ 6 core entities (Account, McEvent, Person, Commitment, Integration, Memory stub)
- ✅ Gmail message normalizer (tested)
- ✅ Event + Person ingestion (tested)
- ✅ AI commitment extraction step (tested with stub)
- ✅ Commitment persistence with confidence threshold (tested)
- ✅ Drift detection (tested)
- ✅ Day Brief assembler (tested)
- ✅ CLI: `myclaudia:brief`, `myclaudia:commitments`, `myclaudia:gmail:sync`
- ✅ Web: `/brief` route renders Day Brief

**Next phase:** Calendar + GitHub ingestion, write-back to email, push notifications, multi-tenant onboarding, AI synthesis layer (Claude API integration for actual Day Brief narrative).
