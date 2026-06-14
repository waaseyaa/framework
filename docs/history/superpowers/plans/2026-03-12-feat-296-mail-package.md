# Issue #296: Add waaseyaa/mail Package

> **For agentic workers:** REQUIRED: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a transport-agnostic mail package to Waaseyaa so apps can send email without vendoring their own solution.

**Architecture:** New `packages/mail/` package at Layer 0 (Foundation/Infrastructure). Transport-agnostic: `MailerInterface` delegates to `TransportInterface` implementations. Twig renderer is optional (accepts `?\Twig\Environment`). No queue integration (future milestone).

**Tech Stack:** PHP 8.4, twig/twig (optional), PHPUnit 10.5

---

## File Structure

```
packages/mail/
├── composer.json
├── src/
│   ├── Envelope.php                    # Value object: to, from, subject, text, html
│   ├── Mailer.php                      # Concrete MailerInterface impl, delegates to transport
│   ├── MailerInterface.php             # Contract: send(Envelope): void
│   ├── MailServiceProvider.php         # Registers mailer + transport from config
│   ├── Twig/
│   │   └── TwigMailRenderer.php        # Renders Twig template into Envelope
│   └── Transport/
│       ├── TransportInterface.php      # Contract: send(Envelope): void
│       ├── ArrayTransport.php          # In-memory collector (testing)
│       └── LocalTransport.php          # Writes to file/error_log (dev)
└── tests/
    └── Unit/
        ├── EnvelopeTest.php
        ├── MailerTest.php
        ├── MailServiceProviderTest.php
        ├── Twig/
        │   └── TwigMailRendererTest.php
        └── Transport/
            ├── ArrayTransportTest.php
            └── LocalTransportTest.php
```

Also modify:
- `composer.json` (root) — add path repo + `@dev` require
- `phpstan.neon` — add `packages/mail/src` to paths

---

### Task 1: Package skeleton and value object

**Files:**
- Create: `packages/mail/composer.json`
- Create: `packages/mail/src/Envelope.php`
- Create: `packages/mail/tests/Unit/EnvelopeTest.php`
- Modify: Root `composer.json` — add path repo + require
- Modify: `phpstan.neon` — add path

- [ ] **Step 1: Create package composer.json**

```json
{
    "name": "waaseyaa/mail",
    "description": "Transport-agnostic mail API for Waaseyaa",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "twig/twig": "^3.0"
    },
    "suggest": {
        "twig/twig": "Required for TwigMailRenderer template support"
    },
    "autoload": {
        "psr-4": {
            "Waaseyaa\\Mail\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Waaseyaa\\Mail\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Create Envelope value object**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

final class Envelope
{
    /**
     * @param list<string> $to
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $to,
        public readonly string $from,
        public readonly string $subject,
        public readonly string $textBody = '',
        public readonly string $htmlBody = '',
        public readonly array $headers = [],
    ) {}
}
```

- [ ] **Step 3: Write EnvelopeTest**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields(): void
    {
        $envelope = new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test',
        );

        $this->assertSame(['user@example.com'], $envelope->to);
        $this->assertSame('noreply@example.com', $envelope->from);
        $this->assertSame('Test', $envelope->subject);
        $this->assertSame('', $envelope->textBody);
        $this->assertSame('', $envelope->htmlBody);
        $this->assertSame([], $envelope->headers);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $envelope = new Envelope(
            to: ['a@example.com', 'b@example.com'],
            from: 'noreply@example.com',
            subject: 'Hello',
            textBody: 'Plain text',
            htmlBody: '<p>HTML</p>',
            headers: ['X-Custom' => 'value'],
        );

        $this->assertCount(2, $envelope->to);
        $this->assertSame('Plain text', $envelope->textBody);
        $this->assertSame('<p>HTML</p>', $envelope->htmlBody);
        $this->assertSame(['X-Custom' => 'value'], $envelope->headers);
    }
}
```

- [ ] **Step 4: Add to root composer.json**

Add path repository and require entry.

- [ ] **Step 5: Add to phpstan.neon paths**

- [ ] **Step 6: Run composer dump-autoload and tests**

Run: `composer dump-autoload && ./vendor/bin/phpunit packages/mail/tests/Unit/EnvelopeTest.php`

- [ ] **Step 7: Commit**

```
git commit -m "feat(#296): add mail package skeleton with Envelope value object"
```

### Task 2: Transport interfaces and implementations

**Files:**
- Create: `packages/mail/src/Transport/TransportInterface.php`
- Create: `packages/mail/src/Transport/ArrayTransport.php`
- Create: `packages/mail/src/Transport/LocalTransport.php`
- Create: `packages/mail/tests/Unit/Transport/ArrayTransportTest.php`
- Create: `packages/mail/tests/Unit/Transport/LocalTransportTest.php`

- [ ] **Step 1: Create TransportInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

interface TransportInterface
{
    public function send(Envelope $envelope): void;
}
```

- [ ] **Step 2: Create ArrayTransport (testing)**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

final class ArrayTransport implements TransportInterface
{
    /** @var list<Envelope> */
    private array $sent = [];

    public function send(Envelope $envelope): void
    {
        $this->sent[] = $envelope;
    }

    /** @return list<Envelope> */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
```

- [ ] **Step 3: Create LocalTransport (dev/file-based)**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

final class LocalTransport implements TransportInterface
{
    public function __construct(
        private readonly string $logPath,
    ) {}

    public function send(Envelope $envelope): void
    {
        $entry = sprintf(
            "[%s] To: %s | From: %s | Subject: %s\n",
            date('Y-m-d H:i:s'),
            implode(', ', $envelope->to),
            $envelope->from,
            $envelope->subject,
        );

        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
```

- [ ] **Step 4: Write ArrayTransportTest**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\ArrayTransport;

#[CoversClass(ArrayTransport::class)]
final class ArrayTransportTest extends TestCase
{
    #[Test]
    public function send_collects_envelopes(): void
    {
        $transport = new ArrayTransport();
        $envelope = new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi');

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(2, $transport->getSent());
        $this->assertSame($envelope, $transport->getSent()[0]);
    }

    #[Test]
    public function reset_clears_sent(): void
    {
        $transport = new ArrayTransport();
        $transport->send(new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi'));
        $transport->reset();

        $this->assertSame([], $transport->getSent());
    }
}
```

- [ ] **Step 5: Write LocalTransportTest**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\LocalTransport;

#[CoversClass(LocalTransport::class)]
final class LocalTransportTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/waaseyaa_mail_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    #[Test]
    public function send_writes_to_log_file(): void
    {
        $transport = new LocalTransport($this->logPath);
        $envelope = new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test Subject',
        );

        $transport->send($envelope);

        $this->assertFileExists($this->logPath);
        $contents = file_get_contents($this->logPath);
        $this->assertStringContainsString('user@example.com', $contents);
        $this->assertStringContainsString('noreply@example.com', $contents);
        $this->assertStringContainsString('Test Subject', $contents);
    }

    #[Test]
    public function send_appends_to_existing_log(): void
    {
        $transport = new LocalTransport($this->logPath);
        $transport->send(new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'First'));
        $transport->send(new Envelope(to: ['c@test.com'], from: 'd@test.com', subject: 'Second'));

        $contents = file_get_contents($this->logPath);
        $this->assertStringContainsString('First', $contents);
        $this->assertStringContainsString('Second', $contents);
    }
}
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/phpunit packages/mail/tests/Unit/Transport/`

- [ ] **Step 7: Commit**

```
git commit -m "feat(#296): add TransportInterface with Array and Local implementations"
```

### Task 3: MailerInterface and Mailer

**Files:**
- Create: `packages/mail/src/MailerInterface.php`
- Create: `packages/mail/src/Mailer.php`
- Create: `packages/mail/tests/Unit/MailerTest.php`

- [ ] **Step 1: Create MailerInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

interface MailerInterface
{
    public function send(Envelope $envelope): void;
}
```

- [ ] **Step 2: Create Mailer**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Mail\Transport\TransportInterface;

final class Mailer implements MailerInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $defaultFrom = '',
    ) {}

    public function send(Envelope $envelope): void
    {
        if ($envelope->from === '' && $this->defaultFrom !== '') {
            $envelope = new Envelope(
                to: $envelope->to,
                from: $this->defaultFrom,
                subject: $envelope->subject,
                textBody: $envelope->textBody,
                htmlBody: $envelope->htmlBody,
                headers: $envelope->headers,
            );
        }

        $this->transport->send($envelope);
    }
}
```

- [ ] **Step 3: Write MailerTest**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\Transport\ArrayTransport;

#[CoversClass(Mailer::class)]
final class MailerTest extends TestCase
{
    #[Test]
    public function send_delegates_to_transport(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport);
        $envelope = new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertCount(1, $transport->getSent());
        $this->assertSame('b@test.com', $transport->getSent()[0]->from);
    }

    #[Test]
    public function send_uses_default_from_when_envelope_from_is_empty(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, defaultFrom: 'default@test.com');
        $envelope = new Envelope(to: ['a@test.com'], from: '', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertSame('default@test.com', $transport->getSent()[0]->from);
    }

    #[Test]
    public function send_preserves_explicit_from_over_default(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, defaultFrom: 'default@test.com');
        $envelope = new Envelope(to: ['a@test.com'], from: 'explicit@test.com', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertSame('explicit@test.com', $transport->getSent()[0]->from);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/mail/tests/Unit/MailerTest.php`

- [ ] **Step 5: Commit**

```
git commit -m "feat(#296): add MailerInterface and Mailer with default-from support"
```

### Task 4: TwigMailRenderer

**Files:**
- Create: `packages/mail/src/Twig/TwigMailRenderer.php`
- Create: `packages/mail/tests/Unit/Twig/TwigMailRendererTest.php`

- [ ] **Step 1: Create TwigMailRenderer**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Twig;

use Twig\Environment;
use Waaseyaa\Mail\Envelope;

final class TwigMailRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * Render a Twig template into an Envelope.
     *
     * @param list<string> $to
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    public function render(
        string $template,
        array $to,
        string $from,
        string $subject,
        array $context = [],
        array $headers = [],
    ): Envelope {
        $htmlBody = $this->twig->render($template, $context);

        return new Envelope(
            to: $to,
            from: $from,
            subject: $subject,
            htmlBody: $htmlBody,
            headers: $headers,
        );
    }
}
```

- [ ] **Step 2: Write TwigMailRendererTest**

Uses Twig `ArrayLoader` (no filesystem needed, per project conventions).

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Mail\Twig\TwigMailRenderer;

#[CoversClass(TwigMailRenderer::class)]
final class TwigMailRendererTest extends TestCase
{
    #[Test]
    public function render_produces_envelope_with_html_body(): void
    {
        $twig = new Environment(new ArrayLoader([
            'welcome.html.twig' => '<h1>Hello {{ name }}</h1>',
        ]));
        $renderer = new TwigMailRenderer($twig);

        $envelope = $renderer->render(
            template: 'welcome.html.twig',
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Welcome',
            context: ['name' => 'Alice'],
        );

        $this->assertSame(['user@example.com'], $envelope->to);
        $this->assertSame('noreply@example.com', $envelope->from);
        $this->assertSame('Welcome', $envelope->subject);
        $this->assertSame('<h1>Hello Alice</h1>', $envelope->htmlBody);
        $this->assertSame('', $envelope->textBody);
    }

    #[Test]
    public function render_passes_headers_through(): void
    {
        $twig = new Environment(new ArrayLoader([
            'simple.html.twig' => 'body',
        ]));
        $renderer = new TwigMailRenderer($twig);

        $envelope = $renderer->render(
            template: 'simple.html.twig',
            to: ['a@test.com'],
            from: 'b@test.com',
            subject: 'Test',
            headers: ['X-Priority' => '1'],
        );

        $this->assertSame(['X-Priority' => '1'], $envelope->headers);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/phpunit packages/mail/tests/Unit/Twig/`

- [ ] **Step 4: Commit**

```
git commit -m "feat(#296): add TwigMailRenderer for templated emails"
```

### Task 5: MailServiceProvider

**Files:**
- Create: `packages/mail/src/MailServiceProvider.php`
- Create: `packages/mail/tests/Unit/MailServiceProviderTest.php`

- [ ] **Step 1: Create MailServiceProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Transport\LocalTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mailConfig = $this->config['mail'] ?? [];
        $transportType = $mailConfig['transport'] ?? 'local';
        $fromAddress = $mailConfig['from_address'] ?? '';

        $this->singleton(TransportInterface::class, match ($transportType) {
            'array' => fn(): ArrayTransport => new ArrayTransport(),
            'local' => fn(): LocalTransport => new LocalTransport(
                $mailConfig['log_path'] ?? $this->projectRoot . '/var/mail.log',
            ),
            default => fn(): LocalTransport => new LocalTransport(
                $mailConfig['log_path'] ?? $this->projectRoot . '/var/mail.log',
            ),
        });

        $this->singleton(MailerInterface::class, fn(): Mailer => new Mailer(
            transport: $this->resolve(TransportInterface::class),
            defaultFrom: $fromAddress,
        ));
    }
}
```

- [ ] **Step 2: Write MailServiceProviderTest**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Mail\MailServiceProvider;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Transport\LocalTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

#[CoversClass(MailServiceProvider::class)]
final class MailServiceProviderTest extends TestCase
{
    #[Test]
    public function register_binds_local_transport_by_default(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', []);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(TransportInterface::class, $bindings);
        $this->assertTrue($bindings[TransportInterface::class]['shared']);
    }

    #[Test]
    public function register_binds_array_transport_when_configured(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']]);
        $provider->register();

        $bindings = $provider->getBindings();
        $transport = ($bindings[TransportInterface::class]['concrete'])();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
    }

    #[Test]
    public function register_binds_mailer_interface(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']]);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(MailerInterface::class, $bindings);
    }

    #[Test]
    public function resolve_produces_working_mailer(): void
    {
        $provider = new class extends MailServiceProvider {
            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array', 'from_address' => 'test@example.com']]);
        $provider->register();

        $mailer = $provider->resolvePublic(MailerInterface::class);
        $this->assertInstanceOf(Mailer::class, $mailer);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/phpunit packages/mail/tests/Unit/MailServiceProviderTest.php`

- [ ] **Step 4: Commit**

```
git commit -m "feat(#296): add MailServiceProvider with transport resolution"
```

### Task 6: Final verification and squash

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage`

- [ ] **Step 2: Run PHPStan**

Run: `php -d memory_limit=512M ./vendor/bin/phpstan analyse`

- [ ] **Step 3: Squash into single commit, push, create PR**

Squash all task commits into:
```
feat(#296): add waaseyaa/mail package

Closes #296
```
