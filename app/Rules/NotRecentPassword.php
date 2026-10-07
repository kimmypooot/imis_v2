<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Security\SecurityPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Refuses the user's current password and, when the reuse setting
 * (password_policy_history) is above 0, that many previous ones.
 */
class NotRecentPassword implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || ! is_string($value) || $value === '') {
            return;
        }

        $depth = SecurityPolicy::historyDepth();
        $hashes = $this->user->passwordHistories()->latest('id')->limit($depth)->pluck('password')
            ->prepend($this->user->getAuthPassword());

        if ($hashes->contains(fn (?string $hash) => $this->matches($value, $hash))) {
            $fail($depth > 0
                ? 'Choose a password you haven\'t used for your last '.($depth + 1).' passwords.'
                : 'Choose a password different from your current one.');
        }
    }

    private function matches(string $password, ?string $hash): bool
    {
        try {
            return filled($hash) && Hash::check($password, $hash);
        } catch (Throwable) {
            // A hash in another format (e.g. imported) can't be compared.
            return false;
        }
    }
}
