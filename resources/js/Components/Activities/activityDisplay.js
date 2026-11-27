/**
 * Calendar of Activities: how an activity's status, time and dates read on
 * screen. An activity PALD returned shows as "Returned" until its office
 * edits it; a cancelled one is struck through.
 */

// StatusBadge statuses (tones) for each activity status.
const statusTones = { planned: 'info', scheduled: 'info', done: 'success', cancelled: 'draft' };

export const activityTone = (activity) => (activity.returned_note ? 'warning' : statusTones[activity.status] ?? 'draft');

export const activityStatusLabel = (activity) => (activity.returned_note ? 'Returned' : activity.status_label);

// Chip colours in the month grid, matching the StatusBadge tones (tokens only).
const chipTones = {
    info: 'border-info-border bg-info-bg text-info-fg',
    success: 'border-success-border bg-success-bg text-success-fg',
    warning: 'border-warning-border bg-warning-bg text-warning-fg',
    draft: 'border-border bg-surface-muted text-text-muted line-through',
};

export const activityChipClass = (activity) => chipTones[activityTone(activity)];

/** "8:00 AM", "12:00 NN" for a H:i time. */
export function clock(time) {
    if (!time) return '';
    const [hours, minutes] = time.split(':').map(Number);
    if (hours === 12 && minutes === 0) return '12:00 NN';
    const suffix = hours >= 12 ? 'PM' : 'AM';
    return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`;
}

/** The short time on a grid chip: "8:00 AM", "Whole day", or nothing. */
export function shortTime(activity) {
    if (activity.all_day) return 'Whole day';
    return clock(activity.starts_at);
}

/** "8:00 AM - 10:00 AM", "8:00 AM - onwards", "Whole day", or "" (as the printed sheet). */
export function timeText(activity) {
    if (activity.all_day) return 'Whole day';
    if (!activity.starts_at) return '';
    return `${clock(activity.starts_at)} - ${activity.ends_at ? clock(activity.ends_at) : 'onwards'}`;
}

/** Whether the activity falls on a day (YYYY-MM-DD), counting each day of a multi-day one. */
export const activityOn = (activity, iso) => !!activity.starts_on && activity.starts_on <= iso && (activity.ends_on ?? activity.starts_on) >= iso;

/** "December 2025" for a month-only activity. */
export const monthName = (year, month) => new Date(year, month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
