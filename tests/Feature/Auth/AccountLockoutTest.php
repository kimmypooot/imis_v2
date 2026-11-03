<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Security\SecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_from_many_addresses_still_lock_one_account(): void
    {
        $user = User::factory()->create();
        $limit = SecurityPolicy::maxLoginAttempts() * 4;

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($attempt + 1)])
                ->post('/login', ['username' => $user->username, 'password' => 'wrong-password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();

        RateLimiter::clear('login-account|'.$user->username);
    }
}
