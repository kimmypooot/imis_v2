<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boot screen's styles are inlined at the top of the page, so it paints
 * before app.css arrives.
 */
class BootScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_boot_preloader_styles_are_inlined_before_the_stylesheet(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $inline = strpos($html, '@layer theme, base, components, utilities;');

        $this->assertNotFalse($inline);
        $this->assertStringContainsString('.imis-preloader', $html);
        $this->assertStringContainsString('id="boot-preloader"', $html);
        $this->assertStringContainsString('window.__bootAt', $html);
        // Ahead of the Vite assets, which is the point.
        $this->assertLessThan(strpos($html, 'app.css') ?: PHP_INT_MAX, $inline);
    }
}
