/**
 * Navigation item shape: { label, href, icon, permission?, description?, create? }.
 * `permission` is a single permission name (must have it) or an array of
 * names (must have at least one) — see the shared SidebarNav (@cscro8/ui). Omit it for items
 * every authenticated user should see. `description` and `create` are only
 * used by the admin dashboard (Admin/Index.vue), ignored elsewhere.
 * `create`, when present, is { permission, href, label } for that module's
 * "New …" shortcut.
 *
 * This is a UX convenience only, never the security boundary — every route
 * behind these links is independently authorized server-side regardless of
 * what the sidebar or dashboard renders.
 *
 * Centralized here so layouts/pages don't hardcode nav arrays inline, and
 * so a future module adds its own entry in one place (and nowhere else)
 * rather than duplicating the permission-filtering logic per surface.
 */

// Any one of these permissions is enough to see the Administration entry
// (sidebar and account menu) — kept in sync with
// AppServiceProvider::ADMIN_ACCESS_PERMISSIONS.
export const adminAccessPermissions = [
    'roles.view',
    'permissions.view',
    'users.view',
    'organizational_units.view',
    'services.view',
    'applications.view',
    'committees.view',
    'announcements.view',
    'audit_logs.view',
    'settings.view',
    'changelogs.view',
    'feedback.view',
    'applications.manage_access',
    'sessions.view',
    'backups.create',
    'api_tokens.manage',
];

// Any one of these permissions is enough to see the Human Resources area
// (sidebar entry, account menu, /admin/hr) — kept in sync with
// AppServiceProvider::HR_ACCESS_PERMISSIONS.
export const hrAccessPermissions = [
    'employees.view',
    'employees.view_unit',
    'pds.view',
    'pds.verify',
    'data_corrections.review',
    'employee_documents.view',
    'ld.view',
    'ld.manage',
    'ld.approve',
    'plantilla.view',
    'plantilla.manage',
    'service_records.view',
    'leave.view',
    'leave.manage',
    'payroll.view',
    'payroll.manage',
    'payroll.tax',
    'dtr.view',
    'dtr.manage',
    'saln.review',
    'saln.manage',
];

/**
 * Portal sidebar: Home and Announcements on their own, then headed groups
 * (Human Resources has collapsible Records, Leave and Payroll sections). The
 * Management
 * group holds only the Executive Dashboard and the two management areas
 * (Human Resources and Administration), so it disappears for users with none
 * (SidebarNav drops empty groups).
 */
export const portalNavItems = [
    { label: 'Home', href: '/dashboard', icon: 'home' },
    { label: 'Announcements', href: '/announcements', icon: 'megaphone' },
    { label: 'Calendar of Activities', href: '/activities', icon: 'calendar', permission: 'activities.view' },
    {
        group: 'Directory',
        items: [
            { label: 'Systems', href: '/applications', icon: 'grid' },
            { label: 'Services', href: '/services', icon: 'clipboard', flag: 'services' },
            { label: 'Organizational Units', href: '/organizational-units', icon: 'building' },
        ],
    },
    {
        group: 'Human Resources',
        items: [
            {
                section: 'Records',
                icon: 'user',
                items: [
                    { label: 'My Employee Record', href: '/my/employee-record', icon: 'user' },
                    { label: 'My Corrections', href: '/my/corrections', icon: 'clipboard' },
                    { label: 'My Personal Data Sheet', href: '/my/pds', icon: 'documentText', keywords: 'pds' },
                    { label: 'My 201 File', href: '/my/201-file', icon: 'database', keywords: 'documents files' },
                    { label: 'My SALN', href: '/my/saln', icon: 'chartBar', keywords: 'assets liabilities net worth' },
                    { label: 'My Service Record', href: '/my/service-record', icon: 'printer' },
                    { label: 'My DTR', href: '/my/dtr', icon: 'clock', keywords: 'daily time record attendance biometric' },
                    { label: 'DTR Entries to Verify', href: '/dtr/entries', icon: 'checkCircle', flag: 'dtrVerifier' },
                    { label: 'My Learning & Development', href: '/my/learning-development', icon: 'activity' },
                ],
            },
            {
                section: 'Leave',
                icon: 'calendar',
                items: [
                    { label: 'My Leave', href: '/my/leave', icon: 'chartBar' },
                    { label: 'File Leave', href: '/my/leave/apply', icon: 'plus', keywords: 'apply application' },
                    { label: 'My Leave Applications', href: '/my/leave/applications', icon: 'clipboard' },
                    { label: 'Leave Calendar', href: '/my/leave/calendar', icon: 'calendar' },
                    { label: 'Leave Monetization', href: '/my/leave/monetization', icon: 'tag' },
                    { label: 'Flexible Work', href: '/my/flexible-work', icon: 'clock' },
                    { label: 'Leave Approvals', href: '/leave/approvals', icon: 'checkCircle', flag: 'leaveApprover' },
                ],
            },
            {
                section: 'Payroll',
                icon: 'database',
                items: [
                    { label: 'My Earnings & Deductions', href: '/my/earnings', icon: 'chartBar', keywords: 'salary pay' },
                    { label: 'My Payslips', href: '/my/payslips', icon: 'documentText', keywords: 'salary pay' },
                    { label: 'Payslip Approvals', href: '/payslips/approvals', icon: 'checkCircle', flag: 'payslipApprover' },
                    { label: 'Payroll Tax Review', href: '/payroll/tax-review', icon: 'checkCircle', flag: 'payrollTaxReviewer', badge: 'payrollTaxReviews' },
                ],
            },
        ],
    },
    {
        group: 'Management',
        items: [
            { label: 'Executive Dashboard', href: '/executive', icon: 'chartBar', permission: 'executive.view' },
        ],
    },
];

/**
 * Admin modules grouped the way the sidebar and the admin dashboard both
 * present them. A module shows up in a group only once it has a real
 * admin surface (index route) behind it — e.g. Users isn't listed yet
 * because only a per-user show/assign-roles route exists, not an index.
 */
/**
 * Admin sidebar, following the legacy IMIS admin panel: Dashboard, then the
 * collapsible groups. The legacy "System Administration" list had grown to
 * sixteen links, so it is split by purpose: Communication, Monitoring & Logs
 * and System Settings; roles and permissions sit with User Management, and
 * the portal-content modules have their own group. Each group's icon shows on its toggle.
 */
export const adminModuleGroups = [
    {
        group: 'User Management',
        icon: 'users',
        items: [
            { label: 'Manage Dashboard', href: '/admin/dashboard-layout', icon: 'grid', permission: 'applications.update' },
            { label: 'Manage Users', href: '/users', icon: 'user', permission: 'users.view' },
            { label: 'Manage Systems', href: '/admin/applications', icon: 'grid', permission: 'applications.view' },
            { label: 'System Access', href: '/admin/system-access', icon: 'key', permission: ['applications.manage_access', 'applications.manage_office_access'] },
            { label: 'User Access', href: '/admin/user-access', icon: 'key', permission: 'applications.manage_access' },
            { label: 'IMIS Roles', href: '/roles', icon: 'users', permission: 'roles.view' },
            { label: 'Permissions', href: '/permissions', icon: 'key', permission: 'permissions.view' },
        ],
    },
    {
        group: 'Portal Content',
        icon: 'building',
        items: [
            { label: 'Organizational Units', href: '/admin/organizational-units', icon: 'building', permission: 'organizational_units.view' },
            { label: 'Services', href: '/admin/services', icon: 'clipboard', permission: 'services.view' },
            { label: 'Service Categories', href: '/admin/service-categories', icon: 'tag', permission: 'services.view' },
            { label: 'Committees', href: '/admin/committees', icon: 'users', permission: 'committees.view' },
        ],
    },
    {
        group: 'Communication',
        icon: 'megaphone',
        items: [
            { label: 'Announcements', href: '/admin/announcements', icon: 'megaphone', permission: 'announcements.view' },
            { label: 'Changelog', href: '/admin/changelogs', icon: 'activity', permission: 'changelogs.view' },
            { label: 'User Feedback', href: '/admin/feedback', icon: 'chat', permission: 'feedback.view', badge: 'feedback' },
        ],
    },
    {
        group: 'Monitoring & Logs',
        icon: 'activity',
        items: [
            { label: 'Active Sessions', href: '/admin/sessions', icon: 'desktop', permission: 'sessions.view' },
            { label: 'Login History', href: '/admin/login-history', icon: 'clock', permission: 'audit_logs.view' },
            { label: 'System Access Logs', href: '/admin/access-logs', icon: 'globe', permission: 'audit_logs.view' },
            { label: 'Security Logs', href: '/admin/security-logs', icon: 'shield', permission: 'audit_logs.view' },
            { label: 'Admin Audit Log', href: '/admin/audit-logs', icon: 'clipboard', permission: 'audit_logs.view' },
        ],
    },
    {
        // Platform configuration and upkeep.
        group: 'System',
        icon: 'cog',
        items: [
            { label: 'System Settings', href: '/admin/settings', icon: 'cog', permission: 'settings.view' },
            { label: 'System Health', href: '/admin/system-health', icon: 'activity', permission: 'settings.view' },
            { label: 'Scheduled Tasks', href: '/admin/scheduled-tasks', icon: 'clock', permission: 'settings.view' },
            { label: 'Integration Tokens', href: '/admin/api-tokens', icon: 'key', permission: 'api_tokens.manage' },
            { label: 'Email Templates', href: '/admin/email-templates', icon: 'envelope', permission: 'settings.view' },
            { label: 'Branding', href: '/admin/branding', icon: 'palette', permission: 'settings.view' },
            { label: 'Backup Database', href: '/admin/backup', icon: 'database', permission: 'backups.create' },
        ],
    },
    {
        // Developer tools: only when the app runs locally (APP_ENV=local);
        // the routes themselves don't exist anywhere else.
        group: 'Developer',
        localOnly: true,
        icon: 'palette',
        items: [
            { label: 'Authorization demo', href: '/authorization-demo', icon: 'shield' },
            { label: 'Design system', href: '/design-system', icon: 'palette' },
        ],
    },
];

export const adminNavItems = [{ label: 'Dashboard', href: '/admin', icon: 'chartBar' }, ...adminModuleGroups];

/**
 * Human Resources sidebar — its own area, apart from system administration
 * (AdminLayout picks it for every /admin/hr page). Dashboard, then one
 * collapsible group per HR function; day-to-day pages first in each,
 * setup pages last.
 */
export const hrNavItems = [
    { label: 'Dashboard', href: '/admin/hr', icon: 'chartBar' },
    {
        group: 'Personnel',
        icon: 'users',
        items: [
            { label: 'Employees', href: '/admin/hr/employees', icon: 'users', permission: ['employees.view', 'employees.view_unit'] },
            { label: 'Reassignments', href: '/admin/hr/reassignments', icon: 'building', permission: ['employees.view', 'employees.view_unit'] },
            { label: 'Age & Service', href: '/admin/hr/age-and-service', icon: 'calendar', permission: 'employees.view' },
            { label: 'PDS Verification', href: '/admin/hr/pds-verifications', icon: 'checkCircle', permission: 'pds.verify', badge: 'pdsVerifications' },
            { label: 'Record Corrections', href: '/admin/hr/corrections', icon: 'clipboard', permission: 'data_corrections.review', badge: 'dataCorrections' },
            { label: 'SALN Compliance', href: '/admin/hr/saln', icon: 'chartBar', permission: 'saln.manage' },
            { label: 'SALN Review', href: '/admin/hr/saln/queue', icon: 'checkCircle', permission: ['saln.review', 'saln.manage'], badge: 'salnReviews' },
        ],
    },
    {
        group: 'Plantilla & Staffing',
        icon: 'grid',
        items: [
            { label: 'Staffing', href: '/admin/hr/staffing', icon: 'building', permission: ['plantilla.view', 'plantilla.manage'] },
            { label: 'Plantilla', href: '/admin/hr/plantilla', icon: 'grid', permission: ['plantilla.view', 'plantilla.manage'] },
            { label: 'Non-Plantilla', href: '/admin/hr/non-plantilla', icon: 'users', permission: ['plantilla.view', 'plantilla.manage'] },
            { label: 'Positions', href: '/admin/hr/positions', icon: 'clipboard', permission: ['plantilla.view', 'plantilla.manage'] },
            { label: 'Salary Schedules', href: '/admin/hr/salary-schedules', icon: 'database', permission: ['plantilla.view', 'plantilla.manage'] },
            { label: 'NOSA & NOSI', href: '/admin/hr/salary-notices', icon: 'document', permission: 'salary_notices.manage' },
        ],
    },
    {
        group: 'Leave',
        icon: 'calendar',
        items: [
            { label: 'Applications', href: '/admin/hr/leave/applications', icon: 'clipboard', permission: ['leave.view', 'leave.manage'], badge: 'leaveApplications' },
            { label: 'Calendar', href: '/admin/hr/leave/calendar', icon: 'calendar', permission: ['leave.view', 'leave.manage'] },
            { label: 'Leave Cards', href: '/admin/hr/leave', icon: 'clipboard', permission: ['leave.view', 'leave.manage'] },
            { label: 'Flexible Work', href: '/admin/hr/leave/flexible-work', icon: 'clock', permission: ['leave.view', 'leave.manage'] },
            { label: 'Tardiness & Undertime', href: '/admin/hr/leave/tardiness', icon: 'clock', permission: ['leave.view', 'leave.manage'] },
            { label: 'Monetization', href: '/admin/hr/leave/monetization', icon: 'database', permission: ['leave.view', 'leave.manage'] },
            { label: 'Reports', href: '/admin/hr/leave/reports', icon: 'printer', permission: ['leave.view', 'leave.manage'] },
        ],
    },
    {
        group: 'Leave Setup',
        icon: 'cog',
        items: [
            { label: 'OIC Designations', href: '/admin/hr/leave/designations', icon: 'users', permission: ['leave.view', 'leave.manage'] },
            { label: 'Leave Types', href: '/admin/hr/leave/types', icon: 'clipboard', permission: ['leave.view', 'leave.manage'] },
            { label: 'Holidays', href: '/admin/hr/leave/holidays', icon: 'calendar', permission: ['leave.view', 'leave.manage'] },
        ],
    },
    {
        group: 'Time Records',
        icon: 'clock',
        items: [
            { label: 'DTR Management', href: '/admin/hr/dtr', icon: 'clipboard', permission: ['dtr.view', 'dtr.manage'] },
            { label: 'Biometric Logs', href: '/admin/hr/dtr/import', icon: 'download', permission: ['dtr.view', 'dtr.manage'] },
            { label: 'Work Schedules', href: '/admin/hr/dtr/schedules', icon: 'clock', permission: ['dtr.view', 'dtr.manage'] },
        ],
    },
    {
        group: 'Payroll',
        icon: 'database',
        items: [
            { label: 'Payrolls', href: '/admin/hr/payroll', icon: 'database', permission: ['payroll.view', 'payroll.manage', 'payroll.tax'], badge: 'payrolls' },
            { label: 'Pay Profiles', href: '/admin/hr/payroll/profiles', icon: 'users', permission: ['payroll.view', 'payroll.manage'] },
            { label: 'Loans', href: '/admin/hr/payroll/loans', icon: 'key', permission: ['payroll.view', 'payroll.manage'] },
            { label: 'Payslip Requests', href: '/admin/hr/payroll/requests', icon: 'clipboard', permission: ['payroll.view', 'payroll.manage'], badge: 'payslipRequests' },
            { label: 'Pay Components', href: '/admin/hr/payroll/components', icon: 'cog', permission: ['payroll.view', 'payroll.manage'] },
        ],
    },
    {
        group: 'Learning & Development',
        icon: 'chartBar',
        items: [
            { label: 'L&D Dashboard', href: '/admin/hr/learning-development/dashboard', icon: 'activity', permission: ['ld.view', 'ld.manage'] },
            { label: 'Records', href: '/admin/hr/learning-development', icon: 'chartBar', permission: ['ld.view', 'ld.manage'] },
            { label: 'Review Queue', href: '/admin/hr/learning-development/reviews', icon: 'checkCircle', permission: 'ld.approve', badge: 'ldReviews' },
            { label: 'Training Report', href: '/admin/hr/learning-development/report', icon: 'printer', permission: ['ld.view', 'ld.manage'] },
        ],
    },
    { label: 'Cutover Readiness', href: '/admin/hr/readiness', icon: 'checkCircle', permission: 'leave.manage' },
];

/**
 * Pages reached from the account menu rather than the sidebar, offered by the
 * "Go to…" palette (Ctrl/⌘+K) alongside every sidebar page.
 */
export const accountNavItems = [
    { label: 'Change password', href: '/account/password', icon: 'key', keywords: 'account security' },
    { label: 'Fingerprint sign-in', href: '/account/passkeys', icon: 'key', keywords: 'passkey face account security' },
    { label: 'Notification settings', href: '/account/notifications', icon: 'bell', keywords: 'email account' },
    { label: 'Notifications', href: '/notifications', icon: 'bell' },
    { label: 'User Guide', href: '/guide', icon: 'help', keywords: 'help how' },
    { label: "What's New", href: '/whats-new', icon: 'megaphone', keywords: 'release changes' },
    { label: 'Who accessed my record', href: '/my/record-access', icon: 'eye', keywords: 'privacy audit' },
    { label: 'Download my data', href: '/my/data-export', icon: 'download', keywords: 'privacy export' },
    { label: 'My Feedback', href: '/my/feedback', icon: 'chat' },
];

/**
 * Every page in a navigation tree, flattened for the "Go to…" palette: each
 * keeps where it lives (`context`, e.g. "My pages · Leave") and the rules the
 * sidebar shows it by (`permission`, `flag`). Groups and sections only add
 * to the context.
 *
 * @param {Array} items
 * @param {string} area
 * @returns {Array<{ label: string, href: string, icon?: string, context: string, keywords?: string, permission?: string|string[], flag?: string }>}
 */
export const flattenNav = (items, area, trail = []) =>
    items.flatMap((item) => {
        if (item.items) {
            return flattenNav(item.items, area, [...trail, item.group ?? item.section].filter(Boolean));
        }

        return item.href
            ? [{ label: item.label, href: item.href, icon: item.icon, keywords: item.keywords, permission: item.permission, flag: item.flag, context: [area, ...trail].join(' · ') }]
            : [];
    });
