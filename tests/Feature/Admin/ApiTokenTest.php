<?php

namespace Tests\Feature\Admin;

use App\Enums\ApiIntegration;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Integrations\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_issued_token_opens_only_its_own_integration(): void
    {
        config(['cares.api_token' => null, 'etts.api_token' => null]);
        [, $plain] = app(ApiTokens::class)->issue(ApiIntegration::Cares, 'Production', null, null);

        $this->withToken($plain)->getJson('/api/v1/cares/employees')->assertOk();
        $this->withToken($plain)->getJson('/api/v1/etts/employees')->assertUnauthorized();
    }

    public function test_the_legacy_env_secret_still_works(): void
    {
        config(['cares.api_token' => 'legacy-secret']);

        $this->withToken('legacy-secret')->getJson('/api/v1/cares/employees')->assertOk();
    }

    public function test_revoked_and_expired_tokens_are_refused(): void
    {
        config(['cares.api_token' => null]);
        $tokens = app(ApiTokens::class);

        [$revoked, $revokedPlain] = $tokens->issue(ApiIntegration::Cares, 'Old', null, null);
        $tokens->revoke($revoked, null);
        [, $expiredPlain] = $tokens->issue(ApiIntegration::Cares, 'Lapsed', null, now()->subDay());

        $this->withToken($revokedPlain)->getJson('/api/v1/cares/employees')->assertUnauthorized();
        $this->withToken($expiredPlain)->getJson('/api/v1/cares/employees')->assertUnauthorized();
    }

    public function test_using_a_token_records_when_and_from_where(): void
    {
        config(['cares.api_token' => null]);
        [$token, $plain] = app(ApiTokens::class)->issue(ApiIntegration::Cares, 'Production', null, null);

        $this->withToken($plain)->getJson('/api/v1/cares/employees')->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
        $this->assertNotNull($token->fresh()->last_used_ip);
    }

    public function test_issuing_shows_the_token_once_and_stores_only_its_hash(): void
    {
        $actor = $this->userWithPermission('api_tokens.manage');

        $response = $this->actingAs($actor)->post('/admin/api-tokens', [
            'integration' => 'cares',
            'name' => 'Production',
            'expires_at' => now()->addYear()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();
        $plain = session('issued_api_token.token');
        $this->assertStringStartsWith('imis_', $plain);

        $stored = ApiToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $stored->token_hash);
        $this->assertStringNotContainsString($plain, json_encode(AuditLog::query()->get()));
        $this->assertSame(1, AuditLog::query()->where('event', 'api_token.created')->count());
    }

    public function test_revoking_locks_the_system_out_and_is_audited(): void
    {
        config(['cares.api_token' => null]);
        $actor = $this->userWithPermission('api_tokens.manage');
        [$token, $plain] = app(ApiTokens::class)->issue(ApiIntegration::Cares, 'Production', null, null);

        $this->actingAs($actor)->delete("/admin/api-tokens/{$token->id}")->assertSessionHasNoErrors();
        auth()->logout();

        $this->withToken($plain)->getJson('/api/v1/cares/employees')->assertUnauthorized();
        $this->assertSame($actor->id, $token->fresh()->revoked_by);
        $this->assertSame(1, AuditLog::query()->where('event', 'api_token.revoked')->count());
    }

    public function test_tokens_close_to_expiry_are_flagged_for_rotation(): void
    {
        $tokens = app(ApiTokens::class);
        [$soon] = $tokens->issue(ApiIntegration::Bmis, 'Soon', null, now()->addDays(5));
        [$later] = $tokens->issue(ApiIntegration::Bmis, 'Later', null, now()->addDays(90));
        [$never] = $tokens->issue(ApiIntegration::Bmis, 'Never', null, null);
        [$revoked] = $tokens->issue(ApiIntegration::Bmis, 'Revoked', null, now()->addDays(2));
        $tokens->revoke($revoked, null);

        $this->assertTrue($soon->expiresSoon());
        $this->assertFalse($later->expiresSoon());
        $this->assertFalse($never->expiresSoon());
        $this->assertFalse($revoked->fresh()->expiresSoon());
    }

    public function test_the_page_needs_the_permission(): void
    {
        $this->actingAs($this->userWithPermission('applications.view'))->get('/admin/api-tokens')->assertForbidden();
        $this->actingAs($this->userWithPermission('api_tokens.manage'))->get('/admin/api-tokens')->assertOk();
    }

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        $user->roles()->attach($role);

        return $user;
    }
}
