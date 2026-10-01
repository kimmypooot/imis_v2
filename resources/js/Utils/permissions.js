/**
 * Permission names are stable `resource.action` identifiers (e.g.
 * `users.view`). Grouping them by the part before the first dot gives a
 * logical, zero-maintenance structure for any UI that lists permissions —
 * no separate "group" column or config needed, and a new module's
 * permissions slot in automatically the moment they're seeded.
 *
 * @param {Array<{id: number, name: string, display_name: string}>} permissions
 * @returns {Array<{group: string, items: Array}>} sorted by group label, each group's items sorted by name
 */
export function groupPermissionsByResource(permissions) {
    const groups = new Map();

    for (const permission of permissions) {
        const [resource] = permission.name.split('.');
        const label = resource
            .split('_')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');

        if (!groups.has(label)) {
            groups.set(label, []);
        }

        groups.get(label).push(permission);
    }

    return [...groups.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([group, items]) => ({
            group,
            items: [...items].sort((a, b) => a.name.localeCompare(b.name)),
        }));
}
