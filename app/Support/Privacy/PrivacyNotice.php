<?php

namespace App\Support\Privacy;

use App\Support\Settings\Settings;

/**
 * Admin-2: the privacy notice at /privacy (RA 10173, the right to be
 * informed). Written from what IMIS actually does, so it stays true as
 * the system changes: what it holds, why, who can see it (the starter
 * roles and their limits), how it's protected, how long the logs are
 * kept (live from System Settings → Log Retention), and the data subject's
 * rights. Who to contact and any text the office adds come from System
 * Settings → Data Privacy. Update this class when a module starts holding
 * a new kind of personal data.
 */
class PrivacyNotice
{
    /** Bump when the wording below changes in substance. */
    public const UPDATED = '2026-10-07';

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'organization' => Settings::get('organization_name'),
            'updated' => self::UPDATED,
            'sections' => [
                [
                    'title' => 'What IMIS holds about you',
                    'items' => [
                        'Your account: name, username, email, position, office, sex, birthday and photo.',
                        'Sign-in history (time, IP address and browser) and the systems you open from the portal.',
                        'Your employee record: employment status, appointment dates, and reassignments, details and designations.',
                        'Your Personal Data Sheet (CS Form No. 212), including family, education, eligibility and work history. Identity numbers (such as GSIS, PhilHealth and TIN) and your answers to items 34–40 are encrypted.',
                        'Documents in your 201 file, your plantilla item and Service Record.',
                        'Leave: applications, credits, and attachments such as medical certificates, which are health information.',
                        'Learning & Development: trainings attended and their certificates.',
                        'Payroll: pay, deductions, loans and payslips.',
                    ],
                ],
                [
                    'title' => 'Why',
                    'items' => [
                        'To manage your employment in the Commission: appointments, records, leave, training and pay, as Civil Service rules require.',
                        'To give you one sign-in to the regional office\'s systems and to keep them secure.',
                    ],
                ],
                [
                    'title' => 'Who can see it',
                    'items' => [
                        'You can see all of your own records.',
                        'Human Resource Division staff, for the records their role covers.',
                        'Your immediate supervisor: your leave applications they act on, and, for office heads, the employee records of their own office — never your Personal Data Sheet or 201 file.',
                        'Top management (the Regional and Assistant Regional Directors): view-only employee records, plantilla, Service Records, training and leave — never your Personal Data Sheet or 201 file — and totals on the Executive Dashboard.',
                        'The Regional Director (or an officer-in-charge), for the final approval of leave.',
                        'System administrators manage accounts and logs; they see HR records only if also given an HR role.',
                        'When you open another regional office system from IMIS, that system receives your name, username, email, position, office, photo and your role in it, so it can sign you in. It receives none of your HR records.',
                    ],
                ],
                [
                    'title' => 'How it is protected',
                    'items' => [
                        'Access is by role and permission, and each page checks it again on the server.',
                        'Every time someone other than you opens or prints your HR records, it is logged. You can see who, what and when under "Who Accessed My Record".',
                        'Identity numbers, items 34–40 of the PDS and signed PDS submissions are encrypted in the database.',
                        'Database backups are kept on the server and downloaded only by Super Administrators, after re-entering their password.',
                    ],
                ],
                [
                    'title' => 'How long it is kept',
                    'items' => [
                        'Your HR records are kept as part of your personnel records; IMIS doesn\'t delete them on its own.',
                        $this->kept('Sign-in history', 'retention_login_history_months'),
                        $this->kept('Records of systems opened from the portal', 'retention_system_access_months'),
                        $this->kept('The log of who accessed your HR records', 'retention_personal_data_access_months'),
                    ],
                ],
                [
                    'title' => 'Your rights',
                    'items' => [
                        'Under the Data Privacy Act of 2012 (RA 10173) you have the right to be informed, to access your data, to object, to have it corrected, to have it erased or blocked when the law allows, to data portability, and to damages.',
                        'Correct your Personal Data Sheet yourself under My Personal Data Sheet; for other records, ask the Human Resource Division.',
                        'You may file a complaint with the National Privacy Commission (privacy.gov.ph).',
                    ],
                ],
            ],
            'contact' => [
                'name' => Settings::get('privacy_officer_name') ?: null,
                'email' => Settings::get('privacy_officer_email') ?: null,
            ],
            'extra' => Settings::get('privacy_notice_extra') ?: null,
        ];
    }

    private function kept(string $what, string $setting): string
    {
        $months = (int) Settings::get($setting);

        return $months > 0
            ? "{$what}: {$months} months, then deleted automatically."
            : "{$what}: kept until the office sets a retention period.";
    }
}
