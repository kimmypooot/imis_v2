/**
 * How the Executive Dashboard writes figures and statuses — shared by the
 * dashboard, its settings and the printed report, so a value or a status
 * reads the same everywhere. Every status pairs a word with its tone:
 * color is never the only signal.
 */
const number = (value, digits) => Number(value).toLocaleString('en-PH', { maximumFractionDigits: digits });

/** A figure with its unit: "86.5%", "₱1,234,567.00", "12 days", "40". Null is a dash. */
export function formatValue(value, unit) {
    if (value === null || value === undefined) return '—';

    switch (unit) {
        case 'percent':
            return `${number(value, 1)}%`;
        case 'peso':
            return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        case 'days':
            return `${number(value, 1)} ${Number(value) === 1 ? 'day' : 'days'}`;
        case 'hours':
            return `${number(value, 1)} ${Number(value) === 1 ? 'hour' : 'hours'}`;
        default:
            return number(value, 2);
    }
}

/** A change with its sign: "+3", "−1.5 points" (percent figures change in points). */
export function formatChange(change, unit) {
    if (change === null || change === undefined) return null;
    if (change === 0) return 'No change';

    const sign = change > 0 ? '+' : '−';
    const size = Math.abs(change);

    if (unit === 'percent') return `${sign}${number(size, 1)} ${size === 1 ? 'point' : 'points'}`;
    if (unit === 'peso') return `${sign}₱${Number(size).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    return `${sign}${formatValue(size, unit)}`;
}

/** Where a figure stands against its target. */
export const targetStatuses = {
    on_track: { label: 'On track', tone: 'success' },
    at_risk: { label: 'At risk', tone: 'warning' },
    behind: { label: 'Behind', tone: 'danger' },
};

/** A system's tile, and a section's freshness. */
export const systemStates = {
    ok: { label: 'Running', tone: 'success' },
    attention: { label: 'Needs attention', tone: 'warning' },
    down: { label: 'Problem', tone: 'danger' },
    maintenance: { label: 'Maintenance', tone: 'warning' },
    failing: { label: 'Not answering', tone: 'danger' },
    stale: { label: 'Not current', tone: 'warning' },
    waiting: { label: 'Waiting', tone: 'neutral' },
    not_connected: { label: 'Not connected', tone: 'neutral' },
};

export const sectionStates = {
    ok: null,
    stale: { label: 'Not current', tone: 'warning' },
    failing: { label: 'Not updating', tone: 'danger' },
    waiting: { label: 'Waiting for the first collection', tone: 'neutral' },
};

/** "5 minutes ago", "3 hours ago", "Jan 4". */
export function formatAge(iso) {
    if (!iso) return 'never';

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/** "Target: at least 90%" / "Target: at most 5 days". */
export function formatTarget(target, unit) {
    if (!target) return null;

    return `Target: ${target.direction === 'lower' ? 'at most' : 'at least'} ${formatValue(target.value, unit)}`;
}
