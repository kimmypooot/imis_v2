<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_forwarded_https_is_ignored_when_no_proxy_is_trusted(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('/login', ['X-Forwarded-Proto' => 'https']);

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_forwarded_https_is_honoured_for_a_trusted_proxy(): void
    {
        $this->app['config']->set('app.url', 'http://imis.test');
        putenv('TRUSTED_PROXIES=10.0.0.5');
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.5';

        try {
            $this->refreshApplication();
            $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
                ->get('/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'imis.test']);

            $response->assertHeader('Strict-Transport-Security');
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES']);
        }
    }
}
