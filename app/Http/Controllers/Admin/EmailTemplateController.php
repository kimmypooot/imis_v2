<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Notifications\TestEmail;
use App\Support\Mail\EmailTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-3, Admin → Email Templates (`/admin/email-templates`): the wording
 * of every email IMIS sends (EmailTemplates). Seen with `settings.view`,
 * changed with `settings.update`. Saving and resetting are audited; a test
 * goes only to the signed-in administrator, with sample values in the
 * placeholders. Unknown template keys are a 404 (the route's `whereIn`).
 */
class EmailTemplateController extends Controller
{
    /** What the placeholders show in a preview or a test. */
    private const SAMPLE = [
        'first_name' => 'Juan',
        'name' => 'Juan S. Dela Cruz',
        'username' => 'jsdelacruz',
        'expires_minutes' => 60,
        'title' => 'Your leave application was approved',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Setting::class);
        $saved = EmailTemplate::query()->with('updater:id,name')->get()->keyBy('key');

        return Inertia::render('Admin/EmailTemplates', [
            'templates' => collect(EmailTemplates::DEFINITIONS)->map(fn (array $definition, string $key) => [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'placeholders' => EmailTemplates::placeholders($key),
                'current' => EmailTemplates::get($key),
                'default' => array_intersect_key($definition, array_flip(['subject', 'intro', 'button', 'outro'])),
                'updatedBy' => $saved->get($key)?->updater?->name,
                'updatedAt' => $saved->get($key)?->updated_at?->toIso8601String(),
            ])->values()->all(),
            'sample' => [
                ...self::SAMPLE,
                'portal_name' => EmailTemplates::fill('{portal_name}', []),
                'organization' => EmailTemplates::fill('{organization}', []),
            ],
            'canUpdate' => $request->user()->can('update', Setting::class),
        ]);
    }

    public function update(UpdateEmailTemplateRequest $request, string $key): RedirectResponse
    {
        $template = EmailTemplate::query()->firstOrNew(['key' => $key]);
        $template->fill([...$request->validated(), 'intro' => $request->validated('intro') ?? '', 'updated_by' => $request->user()->id])->save();

        $this->audit()->record(AuditEvent::EmailTemplateUpdated, 'Changed the email "'.EmailTemplates::DEFINITIONS[$key]['label'].'"', null, ['template' => $key]);

        return back()->with('status', 'Email saved.');
    }

    public function destroy(string $key): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        EmailTemplate::query()->where('key', $key)->delete();
        $this->audit()->record(AuditEvent::EmailTemplateUpdated, 'Put back the default wording of the email "'.EmailTemplates::DEFINITIONS[$key]['label'].'"', null, ['template' => $key, 'reset' => true]);

        return back()->with('status', 'Default wording restored.');
    }

    /** Sends the saved wording, with sample values, to the administrator's own email. */
    public function test(Request $request, string $key): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        if (blank($request->user()->email)) {
            return back()->with('error', 'Your account has no email address.');
        }

        $mail = $this->sampleMail($key);

        Notification::route('mail', $request->user()->email)->notify(new TestEmail($mail));

        return back()->with('status', "Test sent to {$request->user()->email}.");
    }

    /**
     * The email exactly as it will look, in IMIS's mail layout and with
     * sample values: the saved wording, or the draft being typed.
     */
    public function preview(Request $request, string $key): JsonResponse
    {
        $this->authorize('viewAny', Setting::class);

        $draft = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'intro' => ['nullable', 'string', 'max:3000'],
            'button' => ['nullable', 'string', 'max:60'],
            'outro' => ['nullable', 'string', 'max:3000'],
        ]);
        $mail = $this->sampleMail($key, $draft);

        return response()->json([
            'subject' => $mail->subject,
            'from' => ['name' => config('mail.from.name'), 'address' => config('mail.from.address')],
            'to' => $request->user()->email,
            'html' => (string) $mail->render(),
        ]);
    }

    /**
     * @param  array<string, ?string>  $draft
     */
    private function sampleMail(string $key, array $draft = []): MailMessage
    {
        return EmailTemplates::mail(
            $key,
            self::SAMPLE,
            url('/'),
            before: $key === 'notification' ? ['Your leave application for December 15–17, 2026 was approved by the Head of Office. Your leave credits have been updated.'] : [],
            after: $key === 'weekly_summary' ? ['1 of 8 systems needs attention. Against targets: 5 on track, 1 at risk, 0 behind.'] : [],
            details: match ($key) {
                'account_setup', 'password_reset' => ['Username' => self::SAMPLE['username'], 'Link valid for' => self::SAMPLE['expires_minutes'].' minutes'],
                'notification' => ['Received' => now()->format('F j, Y, g:i A')],
                default => [
                    'Systems needing attention' => '1 of 8',
                    'Targets on track' => '5 of 6',
                    'Recruitment System' => 'No data received in the last 24 hours',
                    'Human Resources — Leave applications pending' => '12 (At risk; target 8)',
                ],
            },
            override: $draft,
        );
    }
}
