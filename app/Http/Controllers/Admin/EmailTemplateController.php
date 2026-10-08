<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Notifications\TestEmail;
use App\Support\Mail\EmailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $mail = EmailTemplates::mail(
            $key,
            self::SAMPLE,
            url('/'),
            before: $key === 'notification' ? ['(The notification\'s own text appears here.)'] : [],
            after: $key === 'weekly_summary' ? ['(The list of systems and figures appears here.)'] : [],
        );

        Notification::route('mail', $request->user()->email)->notify(new TestEmail($mail));

        return back()->with('status', "Test sent to {$request->user()->email}.");
    }
}
