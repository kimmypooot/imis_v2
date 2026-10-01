/**
 * Navigation item shape: { label, href, icon, permission?, description?, create? }.
 * `permission` is a single permission name (must have it) or an array of
 * names (must have at least one) — see SidebarNav.vue. Omit it for items
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

export const portalNavItems = [
    { label: 'Home', href: '/dashboard', icon: 'home' },
    { label: 'Announcements', href: '/announcements', icon: 'megaphone' },
    { label: 'Applications', href: '/applications', icon: 'grid' },
    { label: 'Services', href: '/services', icon: 'clipboard' },
    { label: 'Organizational Units', href: '/organizational-units', icon: 'building' },
    // Any one of these permissions is enough to see the Administration
    // entry — kept in sync with AppServiceProvider::ADMIN_ACCESS_PERMISSIONS.
    {
        label: 'Administration',
        href: '/admin',
        icon: 'shield',
        permission: [
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
        ],
    },
];

/**
 * Admin modules grouped the way the sidebar and the admin dashboard both
 * present them. A module shows up in a group only once it has a real
 * admin surface (index route) behind it — e.g. Users isn't listed yet
 * because only a per-user show/assign-roles route exists, not an index.
 */
export const adminModuleGroups = [
    {
        group: 'Portal Management',
        items: [
            {
                label: 'Organizational Units',
                href: '/admin/organizational-units',
                icon: 'building',
                permission: 'organizational_units.view',
                description: 'Manage Regional Support Units, field offices, and their hierarchy.',
                create: {
                    permission: 'organizational_units.create',
                    href: '/admin/organizational-units/create',
                    label: 'New organizational unit',
                },
            },
            {
                label: 'Services',
                href: '/admin/services',
                icon: 'clipboard',
                permission: 'services.view',
                description: 'Manage the CSC services directory.',
                create: {
                    permission: 'services.create',
                    href: '/admin/services/create',
                    label: 'New service',
                },
            },
            {
                label: 'Systems / Applications',
                href: '/admin/applications',
                icon: 'grid',
                permission: 'applications.view',
                description: 'Manage the directory of CSC RO VIII systems IMIS launches.',
                create: {
                    permission: 'applications.create',
                    href: '/admin/applications/create',
                    label: 'New system',
                },
            },
            {
                label: 'Committees',
                href: '/admin/committees',
                icon: 'users',
                permission: 'committees.view',
                description: 'Manage committees, their members, and positions.',
                create: {
                    permission: 'committees.create',
                    href: '/admin/committees/create',
                    label: 'New committee',
                },
            },
            {
                label: 'Announcements',
                href: '/admin/announcements',
                icon: 'megaphone',
                permission: 'announcements.view',
                description: 'Draft, schedule, publish, and archive portal announcements.',
                create: {
                    permission: 'announcements.create',
                    href: '/admin/announcements/create',
                    label: 'New announcement',
                },
            },
        ],
    },
    {
        group: 'Access Management',
        items: [
            {
                label: 'Users',
                href: '/users',
                icon: 'user',
                permission: 'users.view',
                description: 'Manage user accounts, status, and role assignments.',
                create: {
                    permission: 'users.create',
                    href: '/users/create',
                    label: 'New user',
                },
            },
            {
                label: 'Roles',
                href: '/roles',
                icon: 'users',
                permission: 'roles.view',
                description: 'Manage roles and their permissions.',
                create: {
                    permission: 'roles.create',
                    href: '/roles/create',
                    label: 'New role',
                },
            },
            {
                label: 'Permissions',
                href: '/permissions',
                icon: 'key',
                permission: 'permissions.view',
                description: 'View the permissions available in the system.',
            },
            {
                label: 'Audit Logs',
                href: '/admin/audit-logs',
                icon: 'clipboard',
                permission: 'audit_logs.view',
                description: 'Review sign-ins and administrative changes. Read-only.',
            },
        ],
    },
    {
        group: 'System',
        items: [
            {
                label: 'System Settings',
                href: '/admin/settings',
                icon: 'cog',
                permission: 'settings.view',
                description: 'Portal name, portal notice, home page, and contact details.',
            },
            {
                label: "What's New",
                href: '/admin/changelogs',
                icon: 'megaphone',
                permission: 'changelogs.view',
                description: "Write and publish release notes that pop up on everyone's dashboard.",
                create: {
                    permission: 'changelogs.manage',
                    href: '/admin/changelogs/create',
                    label: 'New release note',
                },
            },
        ],
    },
];

export const adminNavItems = [
    { label: 'Administration', href: '/admin', icon: 'shield' },
    ...adminModuleGroups,
    { label: 'Authorization demo', href: '/authorization-demo', icon: 'shield' },
    { label: 'Design system', href: '/design-system', icon: 'palette' },
];
