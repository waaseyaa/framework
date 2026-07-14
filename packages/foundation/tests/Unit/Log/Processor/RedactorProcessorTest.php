<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

#[CoversClass(RedactorProcessor::class)]
final class RedactorProcessorTest extends TestCase
{
    private const REDACTED = '[REDACTED]';

    // -------------------------------------------------------------------------
    // Key denylist: case-insensitive substring match on context key names
    // -------------------------------------------------------------------------

    #[Test]
    public function redacts_exact_lowercase_password_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'login', ['password' => 'secret123']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['password']);
    }

    #[Test]
    public function redacts_uppercase_api_key_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'api call', ['API_KEY' => 'sk-abc123']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['API_KEY']);
    }

    #[Test]
    public function redacts_authorization_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'request', ['Authorization' => 'Bearer tok_xyz']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['Authorization']);
    }

    #[Test]
    public function redacts_token_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'auth', ['token' => 'abc.def.ghi']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['token']);
    }

    #[Test]
    public function redacts_secret_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'config', ['app_secret' => 'super-secret']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['app_secret']);
    }

    #[Test]
    public function redacts_cookie_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'request', ['cookie' => 'session=abc']);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['cookie']);
    }

    // -------------------------------------------------------------------------
    // Benign keys and values pass through untouched
    // -------------------------------------------------------------------------

    #[Test]
    public function passes_through_benign_integer_value(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'user event', ['user_id' => 42]);

        $result = $processor->process($record);

        $this->assertSame(42, $result->context['user_id']);
    }

    #[Test]
    public function passes_through_benign_string_value(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'greet', ['message' => 'hello']);

        $result = $processor->process($record);

        $this->assertSame('hello', $result->context['message']);
    }

    #[Test]
    public function passes_through_multiple_benign_keys(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'request', [
            'user_id' => 99,
            'action'  => 'view',
            'ip'      => '127.0.0.1',
        ]);

        $result = $processor->process($record);

        $this->assertSame(99, $result->context['user_id']);
        $this->assertSame('view', $result->context['action']);
        $this->assertSame('127.0.0.1', $result->context['ip']);
    }

    // -------------------------------------------------------------------------
    // Value-pattern backstop: string values that contain a denylist keyword
    // (case-insensitive substring) are redacted even when the key is benign
    // -------------------------------------------------------------------------

    #[Test]
    public function redacts_string_value_containing_authorization_header(): void
    {
        $processor = new RedactorProcessor();
        // The KEY is benign ("headers"); the VALUE contains "Authorization"
        $record = new LogRecord(LogLevel::INFO, 'request', [
            'headers' => 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9',
        ]);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['headers']);
    }

    #[Test]
    public function redacts_string_value_containing_password_word(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'debug', [
            'raw_body' => 'username=user&password=hunter2',
        ]);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['raw_body']);
    }

    #[Test]
    public function does_not_redact_benign_string_value_without_denylist_keyword(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'event', [
            'description' => 'user logged in successfully',
        ]);

        $result = $processor->process($record);

        $this->assertSame('user logged in successfully', $result->context['description']);
    }

    // -------------------------------------------------------------------------
    // Recursive: nested arrays are walked to arbitrary depth
    // -------------------------------------------------------------------------

    #[Test]
    public function redacts_sensitive_key_in_nested_array(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'api', [
            'request' => [
                'user_id'  => 7,
                'password' => 'p@ssw0rd',
            ],
        ]);

        $result = $processor->process($record);

        $this->assertSame(7, $result->context['request']['user_id']);
        $this->assertSame(self::REDACTED, $result->context['request']['password']);
    }

    #[Test]
    public function redacts_deeply_nested_sensitive_key(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'deep', [
            'level1' => [
                'level2' => [
                    'api_key' => 'sk-deep',
                    'name'    => 'kept',
                ],
            ],
        ]);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['level1']['level2']['api_key']);
        $this->assertSame('kept', $result->context['level1']['level2']['name']);
    }

    #[Test]
    public function preserves_sibling_benign_entries_in_nested_array(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'mixed', [
            'safe_key' => 'safe_value',
            'nested'   => [
                'token'   => 'should-be-gone',
                'user_id' => 123,
            ],
        ]);

        $result = $processor->process($record);

        $this->assertSame('safe_value', $result->context['safe_key']);
        $this->assertSame(self::REDACTED, $result->context['nested']['token']);
        $this->assertSame(123, $result->context['nested']['user_id']);
    }

    // -------------------------------------------------------------------------
    // Immutability: process() returns a new LogRecord, input is not mutated
    // -------------------------------------------------------------------------

    #[Test]
    public function returns_new_log_record_not_the_input(): void
    {
        $processor = new RedactorProcessor();
        $record = new LogRecord(LogLevel::INFO, 'auth', ['password' => 'original']);

        $result = $processor->process($record);

        $this->assertNotSame($record, $result);
    }

    #[Test]
    public function does_not_mutate_original_record_context(): void
    {
        $processor = new RedactorProcessor();
        $original = ['password' => 'original_value', 'user_id' => 1];
        $record = new LogRecord(LogLevel::INFO, 'auth', $original);

        $processor->process($record);

        // The original record's context is unchanged (LogRecord is readonly)
        $this->assertSame('original_value', $record->context['password']);
        $this->assertSame(1, $record->context['user_id']);
    }

    #[Test]
    public function preserves_all_record_fields_except_context(): void
    {
        $processor = new RedactorProcessor();
        $ts = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $record = new LogRecord(LogLevel::WARNING, 'msg', ['password' => 'x'], 'mychannel', $ts);

        $result = $processor->process($record);

        $this->assertSame(LogLevel::WARNING, $result->level);
        $this->assertSame('msg', $result->message);
        $this->assertSame('mychannel', $result->channel);
        $this->assertSame($ts, $result->timestamp);
    }

    // -------------------------------------------------------------------------
    // Extra denylist keys via constructor
    // -------------------------------------------------------------------------

    #[Test]
    public function extra_keys_are_added_to_denylist(): void
    {
        $processor = new RedactorProcessor(['dsn', 'private_key']);
        $record = new LogRecord(LogLevel::INFO, 'db', [
            'dsn'         => 'mysql://' . 'root:pass@localhost/db',
            'user_id'     => 42,
            'token'       => 'still-redacted-by-default',
            'private_key' => 'rsa-key-material',
        ]);

        $result = $processor->process($record);

        $this->assertSame(self::REDACTED, $result->context['dsn']);
        $this->assertSame(self::REDACTED, $result->context['private_key']);
        $this->assertSame(42, $result->context['user_id']);
        $this->assertSame(self::REDACTED, $result->context['token']);
    }
}
