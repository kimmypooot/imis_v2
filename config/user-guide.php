<?php

/*
 * The in-app User Guide (/guide). Plain data: edit the wording here, never
 * in a Vue component. Each category holds task-based articles; an article
 * with `requires` is shown only to people holding at least one of those
 * permissions, so staff see just what applies to them.
 *
 * Article keys: slug, title, summary, steps[], tips[] (optional),
 * link ['label', 'href'] (optional), requires[] (optional permission names).
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
                        'Use the search box in the header to find systems, services, offices and people.',
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
            ],
        ],
        [
            'title' => 'Your HR records',
            'icon' => 'clipboard',
            'articles' => [
                [
                    'slug' => 'personal-data-sheet',
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
                    'title' => 'Using the leave calendar',
                    'summary' => 'See who is away before you plan your own leave.',
                    'steps' => [
                        'Open the Leave Calendar.',
                        'Move between months to see leave and holidays.',
                    ],
                    'link' => ['label' => 'Leave calendar', 'href' => '/my/leave/calendar'],
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
                    'title' => 'Seeing your earnings',
                    'summary' => 'A summary of what you earn and what is deducted.',
                    'steps' => [
                        'Open My Earnings.',
                        'Read your pay components and deductions as payroll holds them.',
                        'Questions about an amount go to the Human Resource Division.',
                    ],
                    'link' => ['label' => 'My Earnings', 'href' => '/my/earnings'],
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
                ],
            ],
        ],
    ],
];
