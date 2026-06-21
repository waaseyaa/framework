<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;

/**
 * #1637: {@see AbstractAgentTool::argumentsForAudit()} must redact a write
 * payload that contains list-valued arguments (e.g. an entity.create
 * `values.blocks` / `tags`) without throwing.
 *
 * Pre-fix it called `strtolower()` on the *integer* keys of a list under
 * `strict_types`, raising an uncaught `TypeError`. The agent runtime calls
 * this on raw, model-controlled tool input (`AgentExecutor` at the audit
 * step, before — and OUTSIDE — the `execute()` try/catch), so a single
 * benign list argument crashed the whole run and skipped its audit row.
 */
#[CoversClass(AbstractAgentTool::class)]
final class AbstractAgentToolArgumentsForAuditTest extends TestCase
{
    private function tool(): AbstractAgentTool
    {
        return new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::text('ok');
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'audit fixture tool';
            }
        };
    }

    #[Test]
    public function redacts_list_valued_arguments_without_throwing(): void
    {
        $args = [
            'entity_type' => 'page',
            'values' => [
                'title' => 'Hello',
                // A list of associative arrays — integer keys 0,1 at this level.
                'blocks' => [
                    ['type' => 'prose', 'html' => '<p>x</p>'],
                    ['type' => 'image', 'src' => '/a.png'],
                ],
                // A list of scalars.
                'tags' => ['php', 'symfony'],
            ],
        ];

        // Pre-fix: TypeError on strtolower() of the integer list keys.
        $redacted = $this->tool()->argumentsForAudit($args);

        // Non-secret content is preserved verbatim, list structure intact.
        self::assertSame($args, $redacted);
    }

    #[Test]
    public function redacts_a_top_level_list_without_throwing(): void
    {
        $redacted = $this->tool()->argumentsForAudit(['items' => ['a', 'b', 'c']]);

        self::assertSame(['items' => ['a', 'b', 'c']], $redacted);
    }

    #[Test]
    public function still_redacts_secret_keys_including_inside_nested_arrays(): void
    {
        $args = [
            'password' => 'hunter2',
            'token' => 'abc',
            'api_key' => 'k',
            'secret' => 's',
            'nested' => ['password' => 'deep', 'safe' => 'keep'],
            'list' => [['ok' => 1]],
        ];

        $redacted = $this->tool()->argumentsForAudit($args);

        self::assertSame('[REDACTED]', $redacted['password']);
        self::assertSame('[REDACTED]', $redacted['token']);
        self::assertSame('[REDACTED]', $redacted['api_key']);
        self::assertSame('[REDACTED]', $redacted['secret']);
        self::assertSame('[REDACTED]', $redacted['nested']['password']);
        self::assertSame('keep', $redacted['nested']['safe']);
        self::assertSame([['ok' => 1]], $redacted['list']);
    }
}
