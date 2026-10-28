<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * resources/css/boot.css can run before the theme has loaded, so each token it
 * uses carries its value as a fallback. They must not drift from @theme.
 */
class BootScreenStylesTest extends TestCase
{
    public function test_the_token_fallbacks_match_the_theme(): void
    {
        $theme = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $boot = file_get_contents(dirname(__DIR__, 2).'/resources/css/boot.css');

        preg_match_all('/var\((--[a-z0-9-]+),\s*(#[0-9a-f]{3,8}|[0-9.]+rem)\)/i', $boot, $uses, PREG_SET_ORDER);

        $this->assertNotEmpty($uses, 'boot.css should carry fallbacks for its tokens.');

        foreach ($uses as [, $token, $fallback]) {
            $this->assertSame(1, preg_match('/^\s*'.preg_quote($token, '/').':\s*([^;]+);/m', $theme, $declared), "{$token} is not declared in app.css.");
            $this->assertSame(strtolower(trim($declared[1])), strtolower($fallback), "{$token} fallback in boot.css differs from @theme.");
        }
    }
}
