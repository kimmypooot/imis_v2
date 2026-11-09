<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

/**
 * A forged Host header must not reach URL generation (password-reset
 * links), so outside local the app trusts only its APP_URL host plus any
 * TRUSTED_HOSTS.
 */
class TrustedHostsTest extends TestCase
{
    public function test_only_the_app_url_host_and_configured_extras_are_trusted(): void
    {
        config(['app.url' => 'https://imis.example.gov.ph', 'app.trusted_hosts' => ['^intranet\.example$']]);

        $patterns = (new TrustHosts($this->app))->hosts();

        $matches = fn (string $host) => collect($patterns)->contains(fn ($pattern) => preg_match('{'.$pattern.'}i', $host) === 1);

        $this->assertTrue($matches('imis.example.gov.ph'));
        $this->assertTrue($matches('intranet.example'));
        $this->assertFalse($matches('evil.example'));
        $this->assertFalse($matches('imis.example.gov.ph.evil.example'));
    }
}
