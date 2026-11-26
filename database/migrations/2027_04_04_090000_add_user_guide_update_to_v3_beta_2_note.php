<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the refreshed User Guide to the 3.0.0-beta.2 What's New draft
 * (created by fold_dev_releases_into_v3_betas, which runs first). Nothing
 * is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $existing = DB::table('changelog_items')->where('changelog_id', $changelog->id)->pluck('description')->all();
            $next = (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1;

            foreach (self::items() as [$type, $description]) {
                if (in_array($description, $existing, true)) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $type,
                    'description' => $description,
                    'sort_order' => $next++,
                ]);
            }

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['improvement', 'When a form can\'t be saved, IMIS takes you straight to the first field that needs fixing, even if it is far up the page or inside a dialog. Long forms (filing leave, your SALN) also list everything to fix in one box at the top.'],
            ['feature', 'Jump to any page: press Ctrl+K (⌘+K on a Mac), type a few letters such as "payslip", "file leave" or "salary", and press Enter. Only pages you can open are listed, and the last option searches the whole portal for what you typed. The Help menu shows the shortcut.'],
            ['improvement', 'See where your request is at a glance. Leave applications, your PDS and your SALN show their route as steps: what is done and by whom, where it is now and who has it (for leave, "Waiting for … since …"), anything returned for correction, and what comes next.'],
            ['feature', 'New to IMIS? The dashboard shows a short "Get set up in IMIS" checklist: save your e-signature, submit your PDS and set up fingerprint sign-in, each with a button that takes you there. Items tick themselves off as you do them, and the checklist disappears once everything is done. "Hide for now" puts it away for 30 days.'],
            ['improvement', 'The hints under form fields (what to attach for a leave type, why a reason is asked, what a password re-entry means) look the same everywhere and are read aloud by screen readers when you move to the field.'],
            ['improvement', 'Pages the User Guide explains show "How does this work?" beside their title. It opens the guide at the article for that page (filing leave, your PDS, SALN, DTR, payslips, approvals and more) and highlights it, so help is one click away from where you are.'],
            ['improvement', 'IMIS asks before you lose unsaved work. Leaving a leave application, your PDS or SALN, System Settings, an email template, payroll withholding taxes or a release note with changes not yet saved asks "Leave without saving?" first; Stay (or Enter) keeps you on the page with everything you typed.'],
            ['improvement', 'Notices that close on their own (Done, Notice, Heads up) show a thin bar that empties as their time runs out. Point at one to hold it open: the bar pauses and starts over when you move away. Errors still stay until you close them.'],
            ['feature', 'Preview a biometric log before uploading it: Preview, beside Replace, shows what the upload would add (new punches against those already on file, month by month), what it would skip, each Biometric ID with the employee it belongs to, and the file\'s first lines. Nothing is saved until you upload.'],
            ['improvement', 'Biometric logs puts the upload first, with Who has submitted, Employees, IDs not on any employee and Recent uploads in tabs below. Each employee counts once, in the office they ended the month in, and Contract of Service personnel aren\'t counted.'],
            ['improvement', 'Uploading a biometric log still stores every month in the file, and now shows the new punches month by month, so late punches for an earlier month stand out. Only the employees whose punches it adds are notified, and punches older than a start date (System Settings: "DTR: keep punches from") can be skipped.'],
            ['improvement', 'Biometric logs lists every employee, office by office, with their Biometric ID, the days they punched and their last punch. Missing people (no punches yet, or no Biometric ID) come first, and each office\'s "missing: see who" opens just its list. The list can be searched and exported to chase them up.'],
            ['improvement', 'The HR dashboard\'s banner shows how many things are waiting on HR and where the month stands, step by step: biometric logs in, DTR entries to verify, tardiness to charge, the payroll, and SALN filing or step increments when they are due. Its buttons are for the tasks at hand (upload a biometric log, open the payroll, review leave). Each person sees only the steps for their own work.'],
            ['improvement', 'Filing leave: leave types you can\'t file yet are greyed out under "Not available to you", each with a short reason (for example, needs 24 months of service), and leave for the other sex is no longer listed.'],
            ['improvement', 'Switch areas in one click: the top of the side navigation now offers Me (your own records, leave and pay), HR and Admin, each shown only if you have access. HRD staff move between their own pages and HR work without scrolling to the bottom of the menu.'],
            ['improvement', 'The home page\'s Notifications tile, which repeated the bell, is now "Late & undertime": this month\'s total so far, and a reminder when last month\'s DTR is waiting for your signature.'],
            ['improvement', 'Staff pages match the rest of IMIS: the filter tiles on My Leave Applications and My Feedback, the filter chips on Notifications, What\'s New, Payslip Approvals and the training log, and the leave card\'s view switch all look and work the same way.'],
            ['improvement', 'DTR entries to verify is a table with each person\'s photo: tick several days and verify them together. Payslip Approvals shows each requester\'s photo.'],
            ['improvement', 'The User Guide covers what IMIS does now: your DTR (missed punches, choosing a verifier, signing), your SALN, record corrections, your e-signature, leave monetization, flexible work, downloading your data, dark mode and installing IMIS as an app, plus how to search and export any list.'],
            ['improvement', 'Supervisors, the head of HRD, Accounting, the SALN Committee and HR staff each see guides for their own work: verifying DTR entries, approving payslips, entering withholding tax, reviewing SALNs, and preparing DTRs, payroll, SALNs and NOSA/NOSI.'],
        ];
    }
};
