import { adminAccessPermissions, adminModuleGroups, hrAccessPermissions, hrNavItems, portalNavItems } from './navigation';

/**
 * Admin-3, permission preview: what a set of permissions opens — worked
 * out from the same navigation definitions the sidebar uses, so it shows
 * exactly the pages the person will see. Used live in the role editor
 * (as permissions are ticked) and on a user's page (as roles are ticked).
 *
 * Every page still checks permissions on the server; this is a preview,
 * not the security boundary.
 *
 * @param {string[]} names permission names, e.g. ['users.view']
 * @param {{ superAdmin?: boolean, labels?: Record<string, string> }} options
 *   `labels` maps permission names to display names, for the "also allowed" list
 * @returns {{ areas: Array<{ area: string, pages: Array<{ label: string, href: string }> }>, other: string[], superAdmin: boolean }}
 */
export function previewAccess(names, { superAdmin = false, labels = {} } = {}) {
    const granted = new Set(names);
    const has = (permission) => superAdmin || !permission || (Array.isArray(permission) ? permission.some((name) => granted.has(name)) : granted.has(permission));
    const used = new Set();
    const note = (permission) => [permission ?? []].flat().forEach((name) => granted.has(name) && used.add(name));

    const pagesOf = (items) =>
        items.flatMap((item) => (item.group ? item.items : [item])).filter((item) => {
            if (!has(item.permission)) return false;
            note(item.permission);

            return true;
        });

    const management = portalNavItems.find((item) => item.group === 'Management')?.items ?? [];
    const areas = [
        { area: 'Management', pages: pagesOf(management) },
        { area: 'Human Resources area', pages: has(hrAccessPermissions) ? pagesOf(hrNavItems) : [] },
        { area: 'Administration area', pages: has(adminAccessPermissions) ? pagesOf([{ label: 'Dashboard', href: '/admin' }, ...adminModuleGroups]) : [] },
    ].filter((area) => area.pages.length);

    // Permissions that open no page of their own: actions inside a page (create, approve, …).
    const other = superAdmin ? [] : [...granted].filter((name) => !used.has(name)).sort().map((name) => labels[name] ?? name);

    return { areas, other, superAdmin };
}
