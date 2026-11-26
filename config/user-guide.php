<?php

/*
 * The in-app User Guide (/guide). Plain data: edit the wording here, never
 * in a Vue component. Each category holds task-based articles; an article
 * with `requires` is shown only to people holding at least one of those
 * permissions, so staff see just what applies to them.
 *
 * Article keys: slug, title, summary, steps[], tips[] (optional),
 * link ['label', 'href'] (optional), requires[] (optional permission names),
 * pages[] (optional: more paths this article explains, beyond its link,
 * as Str::is patterns such as 'my/saln/*'; the page's "How does this work?"
 * link opens it), audience[] (optional roles that come from someone's position, as in the
 * sidebar: leaveApprover, dtrVerifier, payslipApprover, payrollTaxReviewer).
 * An article with requires or audience shows when either one matches.
 */
return [
    'categories' => [
        [
            'title' => 'Getting started',
            'icon' => 'home',
            'articles' => [
                [
                    'slug' => 'sign-in',
                    'title' => 'Signing in and finding your way around',
                    'summary' => 'Sign in once and open every connected CSC RO VIII system from the dashboard.',
                    'steps' => [
                        'Open IMIS and sign in with your username (not your email) and password.',
                        'The dashboard greets you and lists the systems you may open. Click one to launch it without signing in again.',
                        'Use the menu button at the top left to show or hide the side navigation.',
                        'If you work in HR or administer IMIS, switch areas at the top of the side navigation: Me for your own records, leave and pay, HR for Human Resources work, Admin for administration.',
                        'Use the search box in the header to find systems, services, offices and people.',
                        'To jump straight to a page, press Ctrl+K (⌘+K on a Mac), type a few letters of its name, such as "payslip" or "file leave", and press Enter.',
                        'Open your account menu (top right) for account settings, feedback and signing out.',
                    ],
                    'tips' => [
                        'Forgot your password? Choose "Forgot password?" on the sign-in page; the reset link goes to your email.',
                        'For your safety, an idle session is signed out automatically. A warning appears before it happens.',
                    ],
                    'link' => ['label' => 'Go to the dashboard', 'href' => '/dashboard'],
                ],
                [
                    'slug' => 'notifications-bell',
                    'title' => 'Reading your notifications',
                    'summary' => 'Everything IMIS tells you appears under the bell at the top of the page.',
                    'steps' => [
                        'Click the bell. A red number means unread notifications.',
                        'Click a notification to open the page it is about.',
                        'Choose "Mark all as read" to clear the number, or "Show all notifications" for the full list.',
                    ],
                    'link' => ['label' => 'Open all notifications', 'href' => '/notifications'],
                ],
                [
                    'slug' => 'working-with-lists',
                    'title' => 'Searching, sorting and exporting a list',
                    'summary' => 'Every list in IMIS works the same way: search it, sort it, page through it, and copy, download or print it.',
                    'steps' => [
                        'Type in the Search box above a list to narrow it as you type.',
                        'Click a column heading to sort by it; click again to reverse the order.',
                        'Use "Show" to choose how many rows a page holds, and the page numbers below the list to move between pages.',
                        'Choose Export, then "Copy to clipboard", "Download CSV" or "Print" for a copy of the rows.',
                        'Where a list has buttons such as "Pending" or "Approved" above it, click one to see only those.',
                    ],
                    'tips' => [
                        'Some cells stack several labelled figures to keep the list on one screen. The exported copy still gives each figure its own column.',
                        'On a phone, each row becomes a card with its labels beside the values.',
                    ],
                ],
                [
                    'slug' => 'appearance-install',
                    'title' => 'Dark mode and installing IMIS as an app',
                    'summary' => 'Choose a light or dark look, and keep IMIS on your desktop or home screen like an app.',
                    'steps' => [
                        'Open your account menu (top right). Under Appearance, choose Light, Dark or Auto (Auto follows your device).',
                        'Where your browser allows it, the same menu shows "Install IMIS app". Choose it and confirm.',
                        'On an iPhone or iPad, "Install IMIS app" shows how: tap Share in Safari, then "Add to Home Screen".',
                    ],
                    'tips' => [
                        'Printed forms always come out light, whatever you choose.',
                    ],
                ],
                [
                    'slug' => 'whats-new',
                    'title' => "Seeing what's new",
                    'summary' => 'Each month\'s release notes list what was added, improved and fixed.',
                    'steps' => [
                        'Open "What\'s New" from the help menu (the question mark at the top) or the footer.',
                        'Search the notes, or pick New, Improved or Fixed to narrow them.',
                    ],
                    'link' => ['label' => "What's New", 'href' => '/whats-new'],
                ],
            ],
        ],
        [
            'title' => 'Your account',
            'icon' => 'user',
            'articles' => [
                [
                    'slug' => 'change-password',
                    'title' => 'Changing your password',
                    'summary' => 'Choose a new password of your own. Required if an administrator set yours or it has expired.',
                    'steps' => [
                        'Open Account settings, then Password.',
                        'Enter your current password, then your new one twice.',
                        'Watch the strength bar and the list of requirements under the new-password field.',
                        'Choose "Update password".',
                    ],
                    'tips' => [
                        'A passphrase of several unrelated words is long and easy to remember.',
                        'Never share your password. IMIS staff will never ask for it.',
                    ],
                    'link' => ['label' => 'Change my password', 'href' => '/account/password'],
                ],
                [
                    'slug' => 'fingerprint-sign-in',
                    'title' => 'Signing in with your fingerprint or face',
                    'summary' => 'Use your own phone or laptop to sign in without typing your password.',
                    'steps' => [
                        'Sign in with your password on your own phone or laptop.',
                        'Open Account settings, then Fingerprint sign-in, and confirm your password.',
                        'Name the device (for example "My Samsung") and choose "Add this device".',
                        'Your device asks for your fingerprint, face or screen-lock PIN. Approve it.',
                        'Next time, choose "Sign in with fingerprint or face" on the login page.',
                    ],
                    'tips' => [
                        'Only add your own phone or laptop. Never add a shared office computer: anyone who can unlock it could sign in as you.',
                        'Your fingerprint never leaves your phone. IMIS only receives a signature that proves your device unlocked it.',
                        'Lost your phone? Remove it under Account settings, Fingerprint sign-in, or ask the system administrator to remove it for you. Your password still works.',
                        'Your password always works too, whether or not you add a device.',
                    ],
                    'link' => ['label' => 'Set up fingerprint sign-in', 'href' => '/account/passkeys'],
                ],
                [
                    'slug' => 'email-notifications',
                    'title' => 'Choosing which notifications are also emailed',
                    'summary' => 'The bell always shows everything; pick what you also want in your inbox.',
                    'steps' => [
                        'Open Account settings, then Notifications.',
                        'Switch on each kind you want by email, or use "Turn all on" or "Turn all off".',
                        'Choose "Save changes".',
                    ],
                    'tips' => [
                        'Emails go to the address on your account. If it shows "No email address", ask an administrator to add one.',
                        'Password reset emails are always sent.',
                    ],
                    'link' => ['label' => 'Notification settings', 'href' => '/account/notifications'],
                ],
                [
                    'slug' => 'send-feedback',
                    'title' => 'Sending feedback and reading replies',
                    'summary' => 'Tell the administrators what works, what does not, and what you would like to see.',
                    'steps' => [
                        'Open your account menu (or the footer) and choose "Send feedback".',
                        'Pick the type, write a short subject and your message, then send.',
                        'Follow its status and any replies under Account settings, then My feedback.',
                    ],
                    'link' => ['label' => 'My feedback', 'href' => '/my/feedback'],
                ],
                [
                    'slug' => 'record-access',
                    'title' => 'Seeing who opened your HR record',
                    'summary' => 'Under the Data Privacy Act (RA 10173), every time someone else opens or prints your HR records it is logged for you to see.',
                    'steps' => [
                        'Open Account settings, then Record access.',
                        'Read the log: when, who, and what they opened. Use the year filter to narrow it.',
                        'If something looks unexpected, contact the Human Resource Division or the Data Protection Officer. See the privacy notice.',
                    ],
                    'link' => ['label' => 'Who accessed my record', 'href' => '/my/record-access'],
                ],
                [
                    'slug' => 'download-my-data',
                    'title' => 'Downloading a copy of your data',
                    'summary' => 'Under the Data Privacy Act you may have a copy of what IMIS holds about you: your account, employee record, PDS, service record, training, leave and payroll.',
                    'steps' => [
                        'Open Account settings, then "Download my data".',
                        'Under "Get my copy", enter your password and choose "Prepare my download".',
                        'Save the file. The link works for two minutes; choose "Download again" if it ran out.',
                    ],
                    'tips' => [
                        'Keep the file somewhere safe: it holds your personal information.',
                    ],
                    'link' => ['label' => 'Download my data', 'href' => '/my/data-export'],
                ],
            ],
        ],
        [
            'title' => 'Your HR records',
            'icon' => 'clipboard',
            'articles' => [
                [
                    'slug' => 'employee-record-signature',
                    'title' => 'Your employee record and e-signature',
                    'summary' => 'See the record HR keeps about you, and save the signature IMIS places on the forms you sign.',
                    'steps' => [
                        'Open My Employee Record to read your personal and employment details (employee number, biometric ID, employment status, years in the CSC). It is read-only.',
                        'Under "My e-signature", choose Draw to sign with your mouse or finger, or "Upload an image" of your signature on white paper.',
                        'Choose "Save signature". Use Change or Remove later if you need to.',
                    ],
                    'tips' => [
                        'Your signature goes only on the leave forms and Daily Time Records you choose to sign. Each signed form keeps its own copy.',
                    ],
                    'link' => ['label' => 'My Employee Record', 'href' => '/my/employee-record'],
                ],
                [
                    'slug' => 'request-correction',
                    'title' => 'Asking HR to correct your record',
                    'summary' => 'Something wrong on your employee record? Ask HR to fix it, and say why.',
                    'steps' => [
                        'Open My Corrections.',
                        'Under "Ask for a correction", choose what needs correcting, type the corrected value, and explain why it is wrong.',
                        'Choose "Send to HR". HR approves it (your record is updated) or rejects it with a reason; you are told under the bell either way.',
                        'Follow your requests under "Your requests", where a pending one can still be cancelled.',
                    ],
                    'tips' => [
                        'Your office and position change through reassignments and appointments, not here.',
                    ],
                    'link' => ['label' => 'My Corrections', 'href' => '/my/corrections'],
                ],
                [
                    'slug' => 'personal-data-sheet',
                    'pages' => ['my/pds/*'],
                    'title' => 'Filling in and submitting your Personal Data Sheet',
                    'summary' => 'Keep your CS Form 212 (Revised 2026) complete, sign it, and send it to HR for verification.',
                    'steps' => [
                        'Open My Personal Data Sheet from the side navigation.',
                        'Go through each section and save it. Write N/A where something does not apply, and do not abbreviate.',
                        'When everything is complete, choose "Sign and submit" and sign.',
                        'HR verifies your submission. Past, verified copies are kept in My 201 File.',
                    ],
                    'tips' => [
                        'Keep it current. Update it whenever your details change.',
                        'If the page says your employee record is not set up, contact the HR office first.',
                    ],
                    'link' => ['label' => 'Open my PDS', 'href' => '/my/pds'],
                ],
                [
                    'slug' => 'learning-development',
                    'title' => 'Recording your trainings',
                    'summary' => 'Log the trainings and seminars you attended; the Human Resource Development review keeps them accurate.',
                    'steps' => [
                        'Open My Learning & Development.',
                        'Choose "Add training" and fill in the details.',
                        'Save. HRD reviews the entry; once approved it is locked, and you ask HRD to change it.',
                    ],
                    'link' => ['label' => 'My Learning & Development', 'href' => '/my/learning-development'],
                ],
                [
                    'slug' => 'service-record-201',
                    'title' => 'Viewing your Service Record and 201 file',
                    'summary' => 'See the service and documents HR keeps about you.',
                    'steps' => [
                        'Open My Service Record to read your service history, or print it.',
                        'Open My 201 File to see the documents in your personnel file.',
                        'To add or correct a document, send it to the HR office. For a certified copy of your Service Record, also ask the HR office.',
                    ],
                    'link' => ['label' => 'My Service Record', 'href' => '/my/service-record'],
                ],
                [
                    'slug' => 'file-saln',
                    'pages' => ['my/saln/*'],
                    'title' => 'Filing your SALN',
                    'summary' => 'Encode your Statement of Assets, Liabilities and Net Worth (2025 SALN Form, RA 6713), print it on the official form, and file the signed copy.',
                    'steps' => [
                        'Open My SALN. Under "Due", choose Start for the SALN you owe. For another kind (an exit SALN, say), use "Start another SALN".',
                        'Turn on "Start from my last filed SALN" to copy your earlier entries, then update them to the new date.',
                        'Fill in each part. Write N/A where an item does not apply. Choose "Save draft" to continue later.',
                        'When it is complete, choose "Save and prepare printout", then print it and sign every page.',
                        'Bring the hard copy to HRD. The SALN Committee reviews it and either clears it for the oath or returns it with remarks to correct.',
                        'Once cleared, swear to it before the administering officer, scan every page into one file, and upload it under "Upload the signed copy". It goes to your 201 File.',
                    ],
                    'tips' => [
                        'If it is returned, the Committee\'s remarks appear on the SALN. Correct it, prepare a new printout and bring that copy.',
                    ],
                    'link' => ['label' => 'My SALN', 'href' => '/my/saln'],
                ],
            ],
        ],
        [
            'title' => 'Daily Time Record',
            'icon' => 'clock',
            'articles' => [
                [
                    'slug' => 'my-dtr',
                    'title' => 'Viewing and printing your DTR',
                    'summary' => 'Your monthly Daily Time Record (CS Form 48) is built from your fingerprint punches, one sheet per office you served that month.',
                    'steps' => [
                        'Open My DTR and pick the month.',
                        'Check each day: time in and out, late and undertime, and any leave or holiday. Your work schedule is shown above the sheet.',
                        'Once the period has ended, choose "Print CS Form 48" for a paper or PDF copy, or "Export to Excel".',
                    ],
                    'tips' => [
                        'Fix any wrong or missing time first, so the copy you print is final.',
                        'If no sheet appears, HR has not yet set up your employee record or matched your biometric ID.',
                    ],
                    'link' => ['label' => 'My DTR', 'href' => '/my/dtr'],
                ],
                [
                    'slug' => 'dtr-entries',
                    'title' => 'Entering a missed or wrong punch',
                    'summary' => 'When the fingerprint reader missed you, enter your actual time in and out yourself, with a reason. Your supervisor verifies it.',
                    'steps' => [
                        'On My DTR, choose "Edit my entries".',
                        'For each day, type your actual time in and out and the reason (for example, travel or the reader was down).',
                        'Choose Save, read the list of days, tick the certification, and choose "Yes, save them".',
                        'Your supervisor or office head is told and verifies the entries.',
                    ],
                    'tips' => [
                        'Your fingerprint punches are never changed. What you enter is kept beside them and shows as "Employee-entered" on the printed form.',
                        'To take back an entry, clear both times and save.',
                        'Days HRD adjusted, and months already charged to your leave credits, are locked. Ask HRD about those.',
                    ],
                    'link' => ['label' => 'My DTR', 'href' => '/my/dtr'],
                ],
                [
                    'slug' => 'sign-dtr',
                    'title' => 'Choosing your verifier and signing your DTR',
                    'summary' => 'Name who verifies your record, then sign it with your saved e-signature once the period has ended.',
                    'steps' => [
                        'On My DTR, next to "Verified by", choose "Choose verifier" (or "Change verifier") and pick your supervisor or office head. Leave it empty to use the office head.',
                        'Once the period has ended, choose Sign, enter your password, and confirm.',
                        'Your e-signature and a validation code go on the printed form. Anyone can check the code to confirm the copy is genuine.',
                    ],
                    'tips' => [
                        'Save your e-signature on My Employee Record first.',
                        'The "Late & undertime" tile on your home page shows this month so far, and says "sign" with last month\'s name while that sheet is waiting for your signature.',
                        'If HRD changes the sheet after you sign, or you change your verifier, it is marked "Changed since signed". Review it and sign again.',
                    ],
                    'link' => ['label' => 'My DTR', 'href' => '/my/dtr'],
                ],
                [
                    'slug' => 'verify-dtr-entries',
                    'title' => 'Verifying your staff\'s DTR entries',
                    'summary' => 'If you supervise or head an office, verify the times your people entered for days the fingerprint reader missed.',
                    'steps' => [
                        'Open DTR Entries to Verify. You are also told under the bell when someone enters times.',
                        'Choose Verify on a day you can vouch for, or tick several (the box at the top ticks them all) and choose "Verify selected".',
                        'Leave the ones you cannot vouch for, and tell the employee.',
                    ],
                    'link' => ['label' => 'DTR Entries to Verify', 'href' => '/dtr/entries'],
                    'audience' => ['dtrVerifier'],
                ],
            ],
        ],
        [
            'title' => 'Leave',
            'icon' => 'calendar',
            'articles' => [
                [
                    'slug' => 'check-credits',
                    'title' => 'Checking your leave credits',
                    'summary' => 'See your balances and leave card as HR keeps them.',
                    'steps' => [
                        'Open My Leave.',
                        'Read your credits per leave type and the card below them.',
                        'If a balance looks wrong, contact the HR office.',
                    ],
                    'link' => ['label' => 'My Leave', 'href' => '/my/leave'],
                ],
                [
                    'slug' => 'file-leave',
                    'title' => 'Filing a leave application',
                    'summary' => 'File on CS Form No. 6. Your immediate supervisor, HR and the Head of Agency act on it in turn.',
                    'steps' => [
                        'Open File leave.',
                        'Pick the type of leave and the dates. The summary on the side shows the working days counted and your credits.',
                        'Attach any supporting document the leave type needs, then choose "Submit application".',
                        'Follow its progress under My Leave Applications. You are notified at each step.',
                        'If it is returned for correction, open it, fix it, and choose "Resubmit".',
                    ],
                    'tips' => [
                        'Wellness Leave follows CSC MC No. 01, s. 2026: 5 days a year, at most 3 in a row. If it is an emergency, you file it on your return.',
                    ],
                    'link' => ['label' => 'File a leave', 'href' => '/my/leave/apply'],
                ],
                [
                    'slug' => 'my-leave-applications',
                    'pages' => ['leave/applications/*'],
                    'title' => 'Tracking and printing your applications',
                    'summary' => 'See where each application stands and print the CS Form No. 6.',
                    'steps' => [
                        'Open My Leave Applications.',
                        'Open an application to see who has acted on it and any remarks.',
                        'Use the form link on an application to print it.',
                    ],
                    'link' => ['label' => 'My Leave Applications', 'href' => '/my/leave/applications'],
                ],
                [
                    'slug' => 'leave-calendar',
                    'pages' => ['my/leave/calendar/*'],
                    'title' => 'Using the leave calendar',
                    'summary' => 'See who is away before you plan your own leave.',
                    'steps' => [
                        'Open the Leave Calendar and use the arrows to move between months.',
                        'Your leave shows in green and your co-workers\' in blue (who is out, not the kind of leave). Leave still under review or returned is outlined; holidays are light blue.',
                        'Click a day to list everyone out that day, or click an entry for its details.',
                    ],
                    'link' => ['label' => 'Leave calendar', 'href' => '/my/leave/calendar'],
                ],
                [
                    'slug' => 'leave-monetization',
                    'title' => 'Monetizing your Vacation Leave',
                    'summary' => 'Convert part of your Vacation Leave to cash, once a year, while keeping the minimum balance the rules require.',
                    'steps' => [
                        'Open Leave Monetization. The page shows your credits and "You can monetize up to" so many days.',
                        'Enter the days to monetize. An estimate of the amount appears; HR confirms the final figure.',
                        'Add remarks if you like, then choose "File request".',
                        'Follow it under "Your requests". HR approves or rejects it, and marks it released once paid.',
                    ],
                    'tips' => [
                        'If you can\'t monetize yet, the page says why instead of showing the form.',
                    ],
                    'link' => ['label' => 'Leave Monetization', 'href' => '/my/leave/monetization'],
                ],
                [
                    'slug' => 'flexible-work',
                    'title' => 'Flexible work and weekly reports',
                    'summary' => 'Ask for flexitime, a compressed work week or work from home (CSC MC No. 06, s. 2022), and file your weekly field activity reports.',
                    'steps' => [
                        'Open Flexible Work and choose "Request an arrangement".',
                        'Pick the arrangement, the start date (and an end date, or leave it open-ended), your weekly schedule and daily hours, and your reason. Choose "Send to HR".',
                        'While an arrangement is in effect, fill in "Weekly report" with the week\'s targets and accomplishments and choose "Submit report".',
                        'Follow your arrangements and reports on the same page.',
                    ],
                    'tips' => [
                        'For a compressed work week, mark the rest day "Off" in the schedule. It won\'t count as a leave day.',
                        'You can edit a weekly report until HR acknowledges it.',
                    ],
                    'link' => ['label' => 'Flexible Work', 'href' => '/my/flexible-work'],
                ],
            ],
        ],
        [
            'title' => 'Pay',
            'icon' => 'database',
            'articles' => [
                [
                    'slug' => 'request-payslip',
                    'title' => 'Requesting a payslip',
                    'summary' => 'Payslips are released on request once the head of HRD approves them.',
                    'steps' => [
                        'Open My Payslips.',
                        'Under "Request a payslip", pick a month whose payroll is final and choose "Send request".',
                        'Once the head of HRD approves it, choose "View payslip" in your requests list to read and print it.',
                    ],
                    'link' => ['label' => 'My Payslips', 'href' => '/my/payslips'],
                ],
                [
                    'slug' => 'my-earnings',
                    'title' => 'Seeing your earnings and deductions',
                    'summary' => 'A summary of what you earn and what is deducted.',
                    'steps' => [
                        'Open My Earnings & Deductions.',
                        'Read your pay components and deductions as payroll holds them.',
                        'Questions about an amount go to the Human Resource Division.',
                    ],
                    'link' => ['label' => 'My Earnings & Deductions', 'href' => '/my/earnings'],
                ],
            ],
        ],
        [
            'title' => 'Approvals',
            'icon' => 'checkCircle',
            'articles' => [
                [
                    'slug' => 'approve-leave',
                    'title' => 'Acting on leave applications',
                    'summary' => 'If you supervise staff, work in HR, or are the Head of Agency (or an officer-in-charge), act on the applications waiting for you.',
                    'steps' => [
                        'Open Leave Approvals. "Waiting for you" lists what needs your action.',
                        'Open an application and review the dates, credits and any attachment.',
                        'Recommend, certify or approve it, or return it with a remark so the employee can correct it.',
                        'Your recent actions stay listed under "Recently acted on".',
                    ],
                    'link' => ['label' => 'Leave Approvals', 'href' => '/leave/approvals'],
                    'audience' => ['leaveApprover'],
                ],
                [
                    'slug' => 'approve-payslips',
                    'title' => 'Approving payslip requests',
                    'summary' => 'As head of HRD (or designated head of HRD), approve the payslips staff ask for.',
                    'steps' => [
                        'Open Payslip Approvals. New requests are listed oldest first, and you are told under the bell.',
                        'Use the buttons above the list or the search box to find a request.',
                        'Choose Approve to let them view and print that month\'s payslip, or Reject and give a reason.',
                    ],
                    'tips' => [
                        'Your own payslip requests go to someone else.',
                    ],
                    'link' => ['label' => 'Payslip Approvals', 'href' => '/payslips/approvals'],
                    'audience' => ['payslipApprover'],
                ],
                [
                    'slug' => 'payroll-tax-review',
                    'title' => 'Entering withholding tax on a payroll',
                    'summary' => 'For Accounting: HRD sends each month\'s payroll here for the withholding tax, then gets it back to finalize.',
                    'steps' => [
                        'Open Payroll Tax Review. A payroll HRD submitted appears here and under the bell.',
                        'Choose "Review & enter tax" to open it.',
                        'In the Withholding tax list, enter each person\'s tax (blank or 0 for none). Last month\'s tax is shown as a guide. Choose "Save withholding tax".',
                        'Choose "Return to HRD", with a note if you like. HRD then finalizes it.',
                    ],
                    'tips' => [
                        'Your own tax line is entered by someone else.',
                    ],
                    'link' => ['label' => 'Payroll Tax Review', 'href' => '/payroll/tax-review'],
                    'requires' => ['payroll.tax'],
                    'audience' => ['payrollTaxReviewer'],
                ],
                [
                    'slug' => 'saln-committee-review',
                    'title' => 'Reviewing SALNs as a Committee member',
                    'summary' => 'Check each SALN hard copy HRD received, then clear it for the oath or return it for correction.',
                    'steps' => [
                        'Open SALN Review. "For review" lists the hard copies HRD received, oldest first.',
                        'Open one and compare the hard copy with the entries: every applicable item filled in or marked N/A, and every page signed.',
                        'Choose "Clear for oath", or write remarks and choose "Return for correction".',
                    ],
                    'tips' => [
                        'Opening a SALN is logged, like every other access to someone\'s HR records.',
                    ],
                    'link' => ['label' => 'SALN Review', 'href' => '/admin/hr/saln/queue'],
                    'requires' => ['saln.review'],
                ],
            ],
        ],
        [
            'title' => 'For HR staff',
            'icon' => 'briefcase',
            'articles' => [
                [
                    'slug' => 'hr-dtr',
                    'pages' => ['admin/hr/dtr/*'],
                    'title' => 'Biometric logs, schedules and DTR management',
                    'summary' => 'Bring in the biometric punches, give everyone a work schedule, and keep each month\'s Daily Time Records in order.',
                    'steps' => [
                        'Open Biometric logs and upload the device\'s whole export (.dat) under "Upload the consolidated log". Every month in it is stored, punches already on file are skipped, and the result shows the new punches month by month. Employees whose punches it adds are told under the bell.',
                        'Below the upload, the "Who has submitted" tab shows each office for the month. Choose "missing: see who" on an office to list just its missing people (no punches or no Biometric ID) on the Employees tab, or choose Excel to export its DTRs.',
                        'Open Work schedules. Under Assign, tick the employees, pick a schedule and the dates, and apply it to all of them at once.',
                        'Open DTR Management, pick the month and office, and use the buttons (No biometric ID, No work schedule, Entries to verify…) to find what needs attention. Open someone\'s DTR to adjust a day or fix a raw punch.',
                        'After the month ends, open Tardiness & undertime and choose "Sync with DTR" to charge late and undertime minutes to leave credits.',
                    ],
                    'tips' => [
                        'Before uploading, choose Preview beside Replace to see what the file would add: new punches against those already on file, what would be skipped, and whose punches it holds. Nothing is saved until you upload.',
                        'Without a work schedule, the DTR shows punches but no hours.',
                        'A punch belongs to the employee whose profile carries its Biometric ID. Unmatched IDs are listed on Biometric logs.',
                    ],
                    'link' => ['label' => 'DTR Management', 'href' => '/admin/hr/dtr'],
                    'requires' => ['dtr.view', 'dtr.manage'],
                ],
                [
                    'slug' => 'hr-payroll',
                    'pages' => ['admin/hr/payroll/*'],
                    'title' => 'Preparing the monthly payroll',
                    'summary' => 'HRD drafts the month\'s payroll, Accounting enters the withholding tax, and HRD finalizes it for release at month end.',
                    'steps' => [
                        'Open Payrolls and choose "New payroll". Pick the month and choose "Draft payroll". Everyone is built from the plantilla, pay profiles and loans.',
                        'Review the payroll register. Open a person to change, remove or add a line for this month (absences, a one-time allowance), with a reason.',
                        'Choose "Submit to Accounting". The Accountant III enters the withholding tax and returns it.',
                        'When it comes back, check it and choose Finalize. Loans count it as paid, and staff can then request their payslips.',
                    ],
                    'tips' => [
                        '"Compare with workbook" checks the payroll against HRD\'s Excel workbook, and "General Payroll" prints the official sheet.',
                        'A finalized payroll can be reopened to correct it, with a reason that is kept in the audit trail.',
                    ],
                    'link' => ['label' => 'Payrolls', 'href' => '/admin/hr/payroll'],
                    'requires' => ['payroll.manage'],
                ],
                [
                    'slug' => 'hr-saln',
                    'pages' => ['admin/hr/saln/*'],
                    'title' => 'Receiving and filing SALNs',
                    'summary' => 'Track who has filed, receive the hard copies, and file the signed SALNs in the 201 File.',
                    'steps' => [
                        'Open SALN Compliance to see, for the year, who has filed, who is late and who has not started. Mark someone exempt if the law exempts their position.',
                        'When someone brings their printout, open SALN Review and type the reference printed at the foot of each page under "Receive a hard copy". The SALN Committee then reviews it.',
                        'After the Committee clears it and the oath is taken, the signed copy is uploaded by the employee, or by HRD with "File in the 201 File".',
                    ],
                    'tips' => [
                        'Open a SALN and choose "Encode on their behalf" for someone who cannot encode their own.',
                    ],
                    'link' => ['label' => 'SALN Compliance', 'href' => '/admin/hr/saln'],
                    'requires' => ['saln.manage'],
                ],
                [
                    'slug' => 'hr-nosa-nosi',
                    'title' => 'Preparing NOSA and NOSI',
                    'summary' => 'Generate Notices of Salary Adjustment and of Step Increment from the plantilla and salary schedules, print them for signing, then file the signed copies.',
                    'steps' => [
                        'Open NOSA & NOSI. For a new salary schedule, choose "Generate the NOSA batch": one notice for everyone holding a plantilla item when it takes effect.',
                        'For step increments, record the increment (or generate the NOSI for one already on the plantilla) from the list of those due.',
                        'Choose Print. The signature space is left blank for the Regional Director (or the Director III) to sign by hand.',
                        'Scan the signed notices and choose "File signed copies". Files named like NOSA_Dela Cruz, Juan.pdf are matched to their notices and filed in each 201 File.',
                    ],
                    'tips' => [
                        'Directors\' notices come from the Central Office. IMIS sends no email about these.',
                        'Check ratings and any administrative case before recording a step increment: IMIS can\'t see those.',
                    ],
                    'link' => ['label' => 'NOSA & NOSI', 'href' => '/admin/hr/salary-notices'],
                    'requires' => ['salary_notices.manage'],
                ],
                [
                    'slug' => 'hr-pds-verification',
                    'pages' => ['admin/hr/pds-verifications/*'],
                    'title' => 'Verifying a submitted PDS',
                    'summary' => 'Check each PDS exactly as the employee e-signed it, then verify and file it, or return it with what to fix.',
                    'steps' => [
                        'Open PDS Verification. Awaiting Verification lists each signed PDS with the employee, office and when it was signed.',
                        'Open one. Sections changed since the employee\'s last verified PDS are marked, so you can start there; a first submission needs every section read.',
                        'Check the photo and the signature at the top.',
                        'Choose "Verify and file", or write in Remarks what to fix and choose "Return to employee". Remarks are required when returning.',
                    ],
                    'tips' => [
                        'Opening a PDS is logged, as it is personal data.',
                        'A returned PDS comes back to you once the employee fixes it and signs again.',
                    ],
                    'link' => ['label' => 'PDS Verification', 'href' => '/admin/hr/pds-verifications'],
                    'requires' => ['pds.verify'],
                ],
                [
                    'slug' => 'hr-record-corrections',
                    'title' => 'Deciding on record corrections',
                    'summary' => 'Staff ask to correct their own employee record; approving updates it, rejecting needs a reason.',
                    'steps' => [
                        'Open Record Corrections to see each request: the employee, the change asked for, their reason and the status.',
                        'Check the change against the employee\'s documents.',
                        'Choose Approve to update the record, or Reject and give a reason the employee will see.',
                    ],
                    'tips' => [
                        'The employee is told under the bell either way.',
                    ],
                    'link' => ['label' => 'Record Corrections', 'href' => '/admin/hr/corrections'],
                    'requires' => ['data_corrections.review'],
                ],
                [
                    'slug' => 'hr-plantilla',
                    'pages' => ['admin/hr/plantilla/*'],
                    'title' => 'Keeping the plantilla up to date',
                    'summary' => 'Each plantilla item keeps every period of service on it, which is what the Service Record is built from.',
                    'steps' => [
                        'Open Plantilla and use Filled, Vacant and Abolished to find an item, or choose "Add item" for a new one.',
                        'Open an item to see its incumbent and every period of service on it, newest first.',
                        'To fill it, choose Appoint and pick the employee (those with the item\'s position title come first). If they hold another item now, as in a promotion or transfer, that period ends the day before, and their position title and employment status follow the new item.',
                        'For service on the item that has already ended, choose "Record past period". It only adds a line to their Service Record.',
                        'To fix a period, choose Correct on its row. To abolish a vacant item, choose Abolish and give the date.',
                    ],
                    'tips' => [
                        'Leave the monthly salary override blank to follow the salary schedule; set it only when the schedule has no amount for that grade and step, or an agreed rate applies.',
                        '"Incomplete link" on an item means a period is missing details; use Correct to complete it.',
                    ],
                    'link' => ['label' => 'Plantilla', 'href' => '/admin/hr/plantilla'],
                    'requires' => ['plantilla.view', 'plantilla.manage'],
                ],
                [
                    'slug' => 'hr-leave-setup',
                    'pages' => ['admin/hr/leave/types', 'admin/hr/leave/holidays', 'admin/hr/leave/designations'],
                    'title' => 'Setting up leave: types, holidays and officers-in-charge',
                    'summary' => 'The rules filing and counting leave depend on: each leave type, the year\'s holidays and who acts for the Head of Agency.',
                    'steps' => [
                        'Leave Types: open a type to check its rules, its approval route (Supervisor → HR → Head of Agency, or HR → Head of Agency) and who may file it (everyone, women or men). Confirm entitlements against current CSC issuances.',
                        'Holidays: each year, choose "Add holiday" for every day off in the year\'s proclamations, with the proclamation number. Days off are skipped when counting leave days.',
                        'OIC Designations: choose "Add designation" for whoever acts for the Head of Agency on leave applications, with the period and the Office or Special Order number.',
                    ],
                    'tips' => [
                        'Add next year\'s holidays before anyone files leave for January, so the day counts are right.',
                    ],
                    'link' => ['label' => 'Leave Types', 'href' => '/admin/hr/leave/types'],
                    'requires' => ['leave.view', 'leave.manage'],
                ],
                [
                    'slug' => 'hr-ld-reviews',
                    'pages' => ['admin/hr/learning-development/reviews'],
                    'title' => 'Reviewing trainings staff add to their log',
                    'summary' => 'Trainings employees record themselves wait in the Review Queue until HRD approves or rejects them.',
                    'steps' => [
                        'Open the L&D Review Queue. Awaiting Review lists each training with the employee and whether a certificate is attached.',
                        'Open the certificate when there is one and check it against the entry.',
                        'Choose the check to approve, or the cross to reject and give a reason the employee sees on their log.',
                    ],
                    'tips' => [
                        'Your own entries are reviewed by another HRD officer.',
                    ],
                    'link' => ['label' => 'L&D Review Queue', 'href' => '/admin/hr/learning-development/reviews'],
                    'requires' => ['ld.approve'],
                ],
            ],
        ],
    ],
];
