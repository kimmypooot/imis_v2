/**
 * Committee add/edit fields, shared by the committee list and a
 * committee's own page (both open the same RecordModal).
 */
export const committeeFields = (statusOptions) => [
    { key: 'name', label: 'Name', required: true },
    { key: 'short_name', label: 'Short name' },
    { key: 'status', label: 'Status', type: 'select', required: true, options: statusOptions },
    { key: 'effective_from', label: 'Effective from', type: 'date' },
    { key: 'effective_until', label: 'Effective until', type: 'date' },
    { key: 'description', label: 'Description', type: 'textarea' },
];

export const committeeDefaults = { name: '', short_name: '', status: 'active', effective_from: '', effective_until: '', description: '' };

export const committeeNullable = ['short_name', 'effective_from', 'effective_until', 'description'];
