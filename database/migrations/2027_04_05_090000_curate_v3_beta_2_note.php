<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Edits the 3.0.0-beta.2 What's New draft before it is published: the
 * month's notes had grown to 108 entries in the order the work was done,
 * with one subject spread over several entries and two left out of date
 * (the quincena split on payslips, which payroll later dropped, and the
 * dashboard's Notifications tile, since replaced). This replaces them with
 * one entry per subject, the ones staff use most first. Only a draft is
 * touched: a published release keeps its notes. Notes added by later
 * migrations are appended after these as usual.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            DB::table('changelog_items')->where('changelog_id', $changelog->id)->delete();

            foreach (self::items() as $order => [$type, $description]) {
                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $type,
                    'description' => $description,
                    'sort_order' => $order,
                ]);
            }

            DB::table('changelogs')->where('id', $changelog->id)->update([
                'title' => 'November build: daily time records, SALN, payroll through Accounting, fingerprint sign-in, dark mode and help on every page',
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // The earlier entries are not restored; the draft can still be edited under Admin → Changelog.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            // ── New ──────────────────────────────────────────────────────
            ['feature', 'Your Daily Time Record at My DTR: see it, print it or export CS Form 48 to Excel, and use Edit my entries to fill in a missing or wrong time with a reason, then certify it (your fingerprint punches are never changed). Your supervisor or office head verifies entered times under DTR Entries to Verify, with HRD as a fallback; a month already charged to leave credits is closed to new entries. An absent day offers a link to file leave for it.'],
            ['feature', 'E-signatures: save your signature once and use it to sign your DTR, leave applications and approvals. A signed DTR can be checked by scanning its QR code.'],
            ['feature', 'SALN: file your Statement of Assets, Liabilities and Net Worth on the 2025 SALN Form at My SALN. IMIS starts it from your record and PDS, prints it on the official form (with AS-1 and AS-2 sheets as needed), and tracks it until the signed copy is in your 201 File.'],
            ['feature', 'Something wrong on your employee record? Open My Employee Record, choose Request a correction, say what is wrong and why, and HR approves or rejects it. You are told under the bell, and your record is updated once it is approved.'],
            ['feature', 'Sign in with your fingerprint or face: on your own phone or laptop, open Account settings, then Fingerprint sign-in, add the device, and next time choose Sign in with fingerprint or face on the login page. Your fingerprint never leaves your phone, and your password still works.'],
            ['feature', 'New to IMIS? The dashboard shows a short "Get set up in IMIS" checklist: save your e-signature, submit your PDS and set up fingerprint sign-in, each with a button that takes you there. Items tick themselves off as you do them, the checklist disappears once everything is done, and "Hide for now" puts it away for 30 days.'],
            ['feature', 'Jump to any page: press Ctrl+K (⌘+K on a Mac), type a few letters such as "payslip", "file leave" or "salary", and press Enter. Only pages you can open are listed, and the last option searches the whole portal. The Help menu shows the shortcut.'],
            ['feature', 'A dark theme: choose Light, Dark or System from Appearance in your user menu, and IMIS remembers it. Printing stays light.'],
            ['feature', 'Install IMIS on your phone: open it in your phone browser and choose Install app or Add to Home screen. It opens full screen, with the CSC logo and the name CSC RO VIII - IMIS, on iPhone and Android.'],
            ['feature', 'Push notifications: switch them on in Notification settings to get your bell notices on your phone or computer. The Super Administrator allows them in System Settings.'],
            ['feature', 'Payroll covers the whole month, with one release on its last day, and goes through Accounting: HRD prepares it, the Accountant III enters withholding tax under Payroll Tax Review and returns it, and HRD finalizes it. Each step is announced under the bell.'],
            ['feature', 'Payroll prints as a General Payroll grouped by office, ending with an Amount Received column, with remittance schedules for GSIS, PhilHealth, Pag-IBIG, the Welfare Fund and BIR. It can be compared with the accounting office\'s monthly workbook, person by person, and that workbook\'s deductions and loans can be imported.'],
            ['feature', 'DTR Management (HRD): set work schedules (the Compressed Work Week has a 12:00 to 1:00 noon break), review and adjust each employee\'s DTR, and print or export DTRs by employee or for a whole office. The list searches as you type, and work schedules and biometric logs let you tick rows to end, remove or delete them together.'],
            ['feature', 'Biometric logs (HRD): upload the consolidated .dat file once a month, after a Preview that shows what it would add or skip before anything is saved. New punches show month by month, only the employees whose punches it adds are notified, and punches older than a start date can be skipped (System Settings: "DTR: keep punches from").'],
            ['feature', 'Biometric logs show who has submitted (the Regional Support Units count as one office, each field office separately, and Contract of Service personnel aren\'t counted), every employee\'s Biometric ID and last punch with missing people first, IDs not on any employee, and recent uploads. Lists can be searched and exported to chase people up.'],
            ['feature', 'Tardiness and undertime are converted and deducted from leave credits and shown on the leave card. HRD sees the days employees entered themselves and must verify them (or charge anyway, which is logged), can charge all reviewed rows at once, and is reminded to sync. Possible habitual tardiness and absenteeism are flagged.'],
            ['feature', 'SALN for HRD: receive hard copies by the reference on each page, review with the SALN Committee, see who has filed each year, record exemptions, and encode for separated staff. Reminders go out before the deadline.'],
            ['feature', 'NOSA & NOSI: generate Notices of Salary Adjustment for everyone when a new salary schedule takes effect, and Notices of Step Increment for staff who complete three years in their position (leave without pay over 15 days moves the date). They print on the office templates with the signature space blank, to sign by hand or with a PNPKI digital signature.'],
            ['feature', 'Signed NOSAs and NOSIs go straight to each employee\'s 201 File, one at a time or many at once matched by file name (NOSA_Surname, First Name). Directors\' notices stay with the Central Office.'],
            ['feature', 'Age & Service: everyone in service with their age, length of service in government and in the CSC, retirement dates at 60 and 65, and next loyalty award. Filter by office or show birthdays, upcoming retirees or this year\'s loyalty awards; the HR dashboard\'s cards link to it.'],
            ['feature', 'Plantilla: one "CSC Field Office - Leyte" place of assignment over Leyte I and Leyte II (people still work in, and are listed under, Leyte I or II); a "Head (same place of assignment)" designation, which heads the office like an Officer-in-Charge but isn\'t shown as concurrent; and a flag, with one notice under the bell, when a holder\'s account title differs from their item\'s.'],
            ['feature', 'The Executive dashboard has a new layout: a headline on whether anything is wrong, a list of what needs attention, a watch list of figures you star, tabs for each module and trend lines on every figure. Open a figure to see its trend, status and breakdown.'],
            ['feature', 'Every IMIS email has a new look and warmer wording: the CSC logo and system name, a clear button with a link to copy if it fails, key details (such as your username and how long a link is valid) in an easy-to-read panel, and a tidy footer. The weekly summary lists systems needing attention and off-target figures in a table.'],
            ['feature', 'Admin Email Templates shows each email exactly as it will arrive, in Desktop or Phone view, updating as you type, with character counters, placeholder hints and an unsaved-changes marker.'],
            ['feature', 'Manage Users can activate or deactivate several accounts at once.'],
            ['feature', 'Error pages are redesigned: a clear status code, a plain explanation and the next step, such as Try again, Go back or Return to dashboard.'],

            // ── Improved ─────────────────────────────────────────────────
            ['improvement', 'A built-in User Guide: step-by-step help for everyday tasks, with search, a contents list that follows you as you read, a button to the real page and a print option. It covers your DTR, SALN, record corrections, e-signature, leave, monetization, flexible work, downloading your data, dark mode, installing IMIS and working with lists. Supervisors, the head of HRD, Accounting, the SALN Committee and HR staff also see guides for their own work. Open it from the Help button.'],
            ['improvement', 'Pages the User Guide explains show "How does this work?" beside their title. It opens the guide at the article for that page and highlights it, so help is one click away from where you are.'],
            ['improvement', 'See where your request is at a glance. Leave applications, your PDS and your SALN show their route as steps: what is done and by whom, where it is now and who has it (for leave, "Waiting for … since …"), anything returned for correction, and what comes next.'],
            ['improvement', 'IMIS asks before you lose unsaved work. Leaving a leave application, your PDS or SALN, System Settings, an email template, payroll withholding taxes or a release note with changes not yet saved asks "Leave without saving?" first; Stay (or Enter) keeps you on the page with everything you typed.'],
            ['improvement', 'When a form can\'t be saved, IMIS takes you straight to the first field that needs fixing, even far up the page or inside a dialog, and long forms (filing leave, your SALN) list everything to fix in one box at the top. Messages call fields by their names and say what to do, such as "Enter the start date, or pick the dates."'],
            ['improvement', 'The hints under form fields (what to attach for a leave type, why a reason is asked, what a password re-entry means) look the same everywhere and are read aloud by screen readers when you move to the field.'],
            ['improvement', 'The dashboard has an At a glance row (your leave balances, leave in progress, approvals waiting for you, and this month\'s late and undertime, with a reminder when last month\'s DTR is waiting for your signature) and a Your systems row with the four systems you opened most recently. The directory search finds systems directly (press /), and the welcome banner shows your division\'s full name.'],
            ['improvement', 'Switch areas in one click: the top of the side navigation offers Me (your own records, leave and pay), HR and Admin, each shown only if you have access.'],
            ['improvement', 'Filing leave: leave types you can\'t file yet are greyed out under "Not available to you", each with a short reason (for example, needs 24 months of service); leave for the other sex is no longer listed, and Solo Parent Leave shows only to solo parents. Applications follow the latest CSC filing rules and print on CS Form No. 6 on Folio paper.'],
            ['improvement', 'My Leave has a File leave button and shortcuts to your applications, the calendar, monetization and flexible work. My Leave Applications has summary tiles that filter the list, and an application\'s details open in a pop-up. Leave calendars show your own division\'s leave and start the week on Sunday (the head of HRD, a Director IV or III and a Super Administrator still see every office).'],
            ['improvement', 'Leave Approvals, Payslip Approvals and DTR Entries to Verify open with how many are waiting and how long the oldest has waited. Each row shows the person\'s photo and how long it has waited, and DTR entries can be verified several at once.'],
            ['improvement', 'My Earnings opens with your latest earnings, deductions and net pay, a progress bar for each loan, your profile items and a chart of recent net pay. My Payslips and Leave Monetization have summary figures, and My PDS shows how complete each part of the form is.'],
            ['improvement', 'My Employee Record, My 201 File and My Service Record open with a strip of key facts.'],
            ['improvement', 'Account pages are redesigned: a clearer sidebar, a password strength meter with a live match check, on/off switches for email notifications, filters and reply bubbles on My Feedback, and a summary banner on Who Accessed My Record.'],
            ['improvement', 'Notifications are grouped by day, with an icon for each kind, relative times and an Unread filter. What\'s New is a timeline with the latest release highlighted and filters for New, Improved and Fixed. Announcements, Systems and Services have richer cards and detail pages, and their search updates as you type.'],
            ['improvement', 'The HR dashboard\'s banner shows how many things are waiting on HR and where the month stands, step by step: biometric logs in, DTR entries to verify, tardiness to charge, the payroll, and SALN filing or step increments when due. Its buttons are for the tasks at hand, and each person sees only the steps for their own work.'],
            ['improvement', 'Tables work the same everywhere: each person\'s photo (or initials) beside their name, the same search, page size, Copy, CSV and Print, view buttons (Pending, Approved, Vacant…) and filter tiles that look alike, and icon row actions (edit, delete, view, print) with tooltips, larger on touch screens; words stay for decisions such as Approve and Reject. Wide tables fit the page without scrolling sideways, with related figures sharing a cell, while exports still include every column.'],
            ['improvement', 'A clearer, calmer look: dialogs with a solid blue header, icons on action buttons, a slimmer red progress bar and a new spinner, placeholder shapes while things load, and notices that show a bar as their time runs out (point at one to hold it open). Dashboard figures count up as the page opens, dialogs keep the keyboard focus inside them, and motion follows your device\'s reduced-motion setting. The header names the system, printing leaves out the menus, and breadcrumbs start from Home.'],
            ['improvement', 'Offices: field offices use shorter labels (FO-Leyte I, FO-Leyte II, FO-SL, SO-WL, FO-B, FO-S, FO-ES, FO-NS) with their codes unchanged, so connected systems aren\'t affected; the head of an office that groups others sees the records of the offices under it; the plantilla import recognizes the "FO No. 6 Leyte" heading; and the Organizational Units page and office directory are tidier.'],
            ['improvement', 'Accounts and roles: every account has an IMIS role (new accounts start as User, and roles can\'t be cleared to nothing), HR staff have their own role, choosing a user\'s profile photo opens a cropper, and Manage Systems, Committees, Offices, Service Categories and Services have Active and Inactive tabs. A Super Administrator can switch the Services directory off in System Settings (Portal).'],
            ['improvement', 'Connected systems: the Recruitment Portal reads users and an employee\'s verified PDS and 201 documents, the ETTS gets an employees feed, and TIMS receives the staff directory and the Executive dashboard figures.'],
            ['improvement', 'Security hardening: browsers are told to apply stricter protections to every page, and repeated failed sign-ins to one account are limited no matter where they come from.'],
            ['improvement', 'Pages stay quick when IMIS sends emails and notifications, because they are sent in the background. Each message appears once, saving a system\'s access keeps you on the page, and the sign-out dialog has "Sign out" selected, so Enter signs you out.'],

            // ── Fixed ────────────────────────────────────────────────────
            ['fix', 'When someone is promoted or linked to a plantilla item, their office now follows the item\'s place of assignment, so the directory and connected systems show the right office. Undoing the promotion puts it back. Appointing someone to an item whose place of assignment is plantilla-only no longer moves their account out of the office they work in.'],
            ['fix', 'Updating a Contract of Service designation now updates the person\'s position too.'],
            ['fix', 'Number fields that take decimals (leave days, payroll amounts, leave type limits) accept them everywhere, and text fields keep their length limits.'],
            ['fix', 'A one-day leave on the HR dashboard shows its date once instead of as a range.'],
            ['fix', 'Updating or removing a user\'s profile photo always shows a confirmation message, and Super Administrators always see the buttons for newly added features, such as deleting a system and Integration Tokens.'],
        ];
    }
};
