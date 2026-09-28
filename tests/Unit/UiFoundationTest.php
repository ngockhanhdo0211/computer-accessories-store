<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UiFoundationTest extends TestCase
{
    public function test_shared_ui_foundation_contract_is_present(): void
    {
        $root = dirname(__DIR__, 2);
        $tokens = file_get_contents($root.'/tokens.css');
        $css = file_get_contents($root.'/resources/css/app.css');

        $this->assertIsString($tokens);
        $this->assertIsString($css);
        $this->assertStringContainsString('--color-danger: var(--color-error);', $tokens);
        $this->assertStringContainsString('--color-danger-paper: var(--color-error-paper);', $tokens);
        $this->assertStringNotContainsString('.field-message:not(.field-error)', $css);

        foreach (['.page-heading', '.status-badge', '.empty-state', '.action-group'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $this->assertStringContainsString('navigation: fixed workspace side rail + compact contextual topbar', $css);
        $this->assertStringContainsString('.workspace-menu-toggle { display: none; min-height: 2.75rem; flex: none;', $css);
        $this->assertStringContainsString('.workspace-breadcrumbs { display: flex;', $css);
        $this->assertStringContainsString('.workspace-main .breadcrumbs { display: none; }', $css);
        $this->assertStringContainsString('.workspace-breadcrumbs__root, .workspace-breadcrumbs__root-separator { display: none; }', $css);
        $this->assertStringNotContainsString('content: "Hiện tại"', $css);
    }
}
