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

/**
 * How far a figure is toward its target, 0–100, for a progress bar. For
 * "higher is better" it is value ÷ target; for "lower is better" it is 100
 * while at or under the target, then target ÷ value as it overshoots.
 * Null when the figure has no target or no value.
 */
export function targetProgress(metric) {
    const { target, value } = metric;
    if (!target || value === null || value === undefined || !Number(target.value)) return null;

    const ratio = target.direction === 'lower' ? (value <= target.value ? 1 : target.value / value) : value / target.value;

    return Math.round(Math.min(1, Math.max(0, ratio)) * 100);
}

/**
 * Whether a change is good, bad or just a change. Only known once the figure
 * has a target (it says which direction is better); otherwise neutral.
 */
export function changeTone(metric) {
    const value = metric.change?.value;
    if (!value || !metric.target) return 'neutral';

    const better = metric.target.direction === 'lower' ? value < 0 : value > 0;

    return better ? 'success' : 'danger';
}

/** One plain sentence about a figure for people who do not read tile jargon. */
export function plainStatus(metric) {
    const now = formatValue(metric.value, metric.unit);
    if (!metric.target) return `Currently ${now}. No target has been set for this figure yet.`;

    const goal = `${metric.target.direction === 'lower' ? 'at most' : 'at least'} ${formatValue(metric.target.value, metric.unit)}`;
    const word = { on_track: 'on track', at_risk: 'slightly short of its target', behind: 'behind its target' }[metric.status] ?? 'being tracked';

    return `Currently ${now}, ${word} (goal: ${goal}).`;
}

/** The overall verdict sentence and tone for the headline band. */
export function headline(summary, notUpdating) {
    const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
    const problems = [];
    if (summary.behind) problems.push(`${plural(summary.behind, 'figure is', 'figures are')} behind target`);
    if (summary.atRisk) problems.push(`${plural(summary.atRisk, 'figure is', 'figures are')} at risk`);
    if (summary.systemsNeedingAttention) problems.push(`${plural(summary.systemsNeedingAttention, 'system needs', 'systems need')} attention`);
    if (notUpdating) problems.push(`${plural(notUpdating, 'source is', 'sources are')} not updating`);

    if (!problems.length) {
        return { tone: 'success', text: summary.withTargets ? 'Everything is on track.' : 'Every system is running.' };
    }

    return { tone: summary.behind || summary.systemsNeedingAttention ? 'danger' : 'warning', text: `${problems.join('; ')}.` };
}
