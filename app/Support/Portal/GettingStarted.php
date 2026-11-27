<?php

namespace App\Support\Portal;

use App\Enums\PdsSubmissionStatus;
use App\Models\User;

/**
 * The dashboard's "Get set up" checklist: the few one-time things that make
 * IMIS work for someone, each worked out from their data rather than ticked
 * by hand. People with an employee record save their e-signature and submit
 * their PDS; everyone may add fingerprint sign-in. Once every item is done
 * the checklist is gone (null), so it never nags settled staff.
 */
class GettingStarted
{
    /**
     * @return array{done: int, total: int, items: array<int, array{key: string, label: string, description: string, href: string, action: string, done: bool}>}|null
     */
    public function for(User $user): ?array
    {
        $items = [];

        if ($user->employee) {
            $items[] = [
                'key' => 'signature',
                'label' => 'Save your e-signature',
                'description' => 'Draw or upload it once; it is placed on the leave forms, DTRs and other forms you choose to sign.',
                'href' => '/my/employee-record',
                'action' => 'Add signature',
                'done' => $user->signature_path !== null,
            ];

            $items[] = [
                'key' => 'pds',
                'label' => 'Submit your Personal Data Sheet',
                'description' => 'Fill in your PDS and submit it, so HR can verify it for your 201 file.',
                'href' => '/my/pds',
                'action' => 'Open my PDS',
                'done' => $user->employee->submissions()
                    ->whereIn('status', [PdsSubmissionStatus::Submitted, PdsSubmissionStatus::Verified])
                    ->exists(),
            ];
        }

        $items[] = [
            'key' => 'passkey',
            'label' => 'Sign in with your fingerprint or face',
            'description' => 'Faster than typing your password, on a phone or laptop that supports it. Your password still works.',
            'href' => '/account/passkeys',
            'action' => 'Set it up',
            'done' => $user->passkeys()->exists(),
        ];

        $done = count(array_filter($items, fn (array $item): bool => $item['done']));

        return $done === count($items) ? null : [
            'done' => $done,
            'total' => count($items),
            'items' => $items,
        ];
    }
}
