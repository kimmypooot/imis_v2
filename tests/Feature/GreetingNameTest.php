<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The sign-in/out screens and greetings use the honorific and the whole
 * first name, not the first word of the full name.
 */
class GreetingNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_greeting_is_the_honorific_and_the_whole_first_name(): void
    {
        $user = User::factory()->create(['honorific' => 'Atty.', 'first_name' => 'Juan Pedro', 'last_name' => 'Cruz']);

        $this->assertSame('Atty. Juan Pedro', $user->greetingName());
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.greeting', 'Atty. Juan Pedro'));
    }

    public function test_without_an_honorific_it_is_just_the_first_name(): void
    {
        $user = User::factory()->create(['honorific' => null, 'first_name' => 'Maria Cristina', 'last_name' => 'Reyes']);

        $this->assertSame('Maria Cristina', $user->greetingName());
    }

    public function test_an_account_without_a_split_name_has_no_greeting(): void
    {
        $user = User::factory()->create(['first_name' => null]);

        $this->assertNull($user->greetingName());
    }
}
