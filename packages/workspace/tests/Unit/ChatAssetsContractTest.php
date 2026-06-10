<?php

declare(strict_types=1);

namespace Waaseyaa\Workspace\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workspace\WorkspaceAssetController;

/**
 * The extraction promises of the chat surface, pinned at the source level:
 * nothing app-specific is hardcoded, and the donor's known sharp edges
 * (blanket target="_blank", pillar-status pills, optimistic sends) did not
 * ride along.
 */
final class ChatAssetsContractTest extends TestCase
{
    private static function asset(string $file): string
    {
        return (string) file_get_contents(WorkspaceAssetController::assetsDir() . '/' . $file);
    }

    #[Test]
    public function the_markdown_renderer_has_no_hardcoded_status_vocabulary(): void
    {
        $md = self::asset('workspace-md.js');

        // The donor pinned the four Anokii pillar statuses; the extraction
        // takes them as configuration.
        $this->assertStringNotContainsString('defined: 1', $md);
        $this->assertStringNotContainsString("'defined'", $md);
        $this->assertStringContainsString('statusVocab', $md);
    }

    #[Test]
    public function links_go_through_the_policy_hook_not_blanket_new_tabs(): void
    {
        $md = self::asset('workspace-md.js');

        $this->assertStringContainsString('linkPolicy', $md);
        $this->assertStringContainsString("data-ws-open=\"panel\"", $md);
        // target="_blank" exists only as the 'blank' policy branch, paired
        // with rel=noopener; the donor applied it unconditionally.
        $this->assertSame(
            substr_count($md, 'target="_blank"'),
            substr_count($md, 'rel="noopener"'),
        );
    }

    #[Test]
    public function the_chat_client_carries_the_contract_behaviors(): void
    {
        $chat = self::asset('workspace-chat.js');

        foreach ([
            'pending',            // honest send states
            'markFailed',
            'rehydrate',          // stream-drop resync + pagination
            'has_more',
            'before=',
            'proposer_uid',       // viewer-mode proposals
            'Awaiting',
            'sessionStorage',     // MPA snapshot
            'proposalRouter',     // app hooks
            'refreshStrategy',
            "addEventListener('submit'", // real form semantics
            'maxlength',          // limit-aware composer
        ] as $marker) {
            $this->assertStringContainsString($marker, $chat, $marker);
        }

        // No hardcoded app endpoints or app thread keys.
        $this->assertStringNotContainsString('/anokii/', $chat);
        $this->assertStringNotContainsString('anokiiIdentityCid', $chat);
        // No automatic page reload: refresh is the app's hook to make.
        $this->assertStringNotContainsString('location.reload', $chat);
    }

    #[Test]
    public function the_chat_panel_template_is_a_real_form_at_a_safe_path(): void
    {
        $template = dirname(__DIR__, 2) . '/templates/workspace/chat-panel.html.twig';
        $this->assertFileExists($template);
        $html = (string) file_get_contents($template);
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('data-ws-stream', $html);
        $this->assertStringContainsString('data-ws-counter', $html);
    }
}
