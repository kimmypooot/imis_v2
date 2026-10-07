<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.0.0 What's New release: the VILT rebuild and what came with it.
 * Added as a draft, so an admin reviews it in Admin → Changelogs and
 * publishes it (publishing shows it to everyone and makes 3.0.0 the
 * version shown). Skipped when 3.0.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $this->insert();
        });
    }

    private function insert(): void
    {
        $now = now();
        $id = DB::table('changelogs')->insertGetId([
            'version' => self::VERSION,
            'title' => 'IMIS rebuilt on VILT, with Human Resources built in',
            'release_date' => '2026-10-05',
            'status' => 'draft',
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('changelog_items')->insert(array_map(
            fn (array $item, int $index) => [
                'changelog_id' => $id,
                'type' => $item[0],
                'description' => $item[1],
                'sort_order' => $index,
            ],
            self::items(),
            array_keys(self::items()),
        ));
    }

    public function down(): void
    {
        // Only an unpublished draft is removed; a published release stays.
        DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['improvement', 'IMIS has been rebuilt from the ground up on the VILT stack — Vue 3, Inertia.js, Laravel and Tailwind CSS — replacing the PHP PDO + Ajax/JSON + Bootstrap pages. Pages now switch without full reloads, forms check your input as you go, and every action is validated and authorized on the server.'],
            ['improvement', 'Stronger security: sign-in by username with throttled attempts and session limits, role- and permission-based access on every page, and a tamper-proof audit trail of administrative actions.'],
            ['feature', 'Single sign-on to the connected systems: open them from the portal without signing in again.'],
            ['feature', 'Portal: Systems Directory with division drill-down, a Services directory, announcements, committees, and one search box across systems, services, offices and announcements.'],
            ['feature', 'Human Resources is now part of IMIS, replacing PRISM, ldms and the LMS: employee records and 201 file documents, and the Personal Data Sheet (CS Form 212, Revised 2026) with e-signing, HR verification and printing.'],
            ['feature', 'Learning & Development: log your trainings, with HRD review, an L&D dashboard and training report; existing ldms records are carried over.'],
            ['feature', 'Plantilla, appointments and salary schedules, plus your Service Record built from them in the standard CSC format.'],
            ['feature', 'Leave: file on CS Form 6 with supervisor → HR → Head approval (OIC designations supported), notifications, a printable form, your leave card and credits, monetization and terminal leave, flexible work arrangements with weekly reports, and leave reports. Wellness Leave follows CSC MC No. 01, s. 2026.'],
            ['feature', 'Leave Calendar: see your own leave plus co-workers in your division who are out (names and dates only), and HR gets an office-wide calendar filtered by office and type of leave.'],
            ['feature', 'Payroll: monthly payrolls released on the 15th and 30th, and payslips you can request, approved by the head of HRD.'],
            ['feature', 'Administration: users and roles, per-system roles, system settings, maintenance mode, database backup, and activity, login and security logs.'],
            ['improvement', 'Your personal data is protected under RA 10173: HR records are permission-gated, every view by someone else is logged, and sensitive PDS details are encrypted.'],
            ['improvement', 'Easier to use for everyone: clearer colors that meet WCAG AA contrast, keyboard-friendly focus, and reduced animation when your device asks for it. The welcome banner keeps its orbiting circles, and Recent Admin Activity is more compact.'],
            ['fix', 'The IMIS version shown in the header, About dialog and dashboard now always matches the latest release notes.'],
        ];
    }
};
